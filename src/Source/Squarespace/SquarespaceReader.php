<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Squarespace;

use DateTimeImmutable;
use DateTimeZone;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RateLimit;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;

/**
 * Reads a site's store from Squarespace's Commerce APIs. Lists are paged by Squarespace's cursor (the first
 * request carries the filters, later ones only `cursor`, which Squarespace can't combine with filters),
 * except discounts, which are paged by offset. Every node carries its identity as `id`
 * (`gid://squarespace/<Type>/<id>`). Orders arrive with their transaction documents (payments, refunds and
 * taxes) and their customer's profile, when Squarespace has one, injected as `transactions` and
 * `customer_profile`. Products and orders are incremental (by modification time, which Squarespace takes
 * only as a window: after the last run and before this reader was made); store pages, profiles and
 * discounts have no such filter and are read in full. Requests are paced under Squarespace's 300 a minute;
 * a 429 pauses the run for Squarespace's one-minute cool-down.
 */
final class SquarespaceReader implements SourceReader
{
    public const API_BASE = 'https://api.squarespace.com';
    /** Squarespace allows 300 requests a minute (developers.squarespace.com/commerce-apis/rate-limits). */
    public const REQUESTS_PER_MINUTE = 300;
    /** Squarespace answers 429 and then refuses requests for a minute (it sends no Retry-After). */
    public const RATE_LIMIT_COOLDOWN_SECONDS = 60.0;
    private const SECONDS_PER_MINUTE = 60.0;
    /** The discounts list takes 1–1000 per page. */
    public const DISCOUNT_PAGE_SIZE = 1000;
    /** Every payment state: without the filter, Squarespace lists only NOT_CHARGED, AUTHORIZED, PAID and REFUNDED orders. */
    public const ORDER_PAYMENT_STATES = 'NOT_CHARGED,AUTHORIZED,PAID,REFUNDED,PENDING,FAILED,REFUND_PENDING,REFUND_FAILED,PARTIALLY_PAID';
    /** Profiles are read by id in batches of at most this many (an order page holds at most 50 orders). */
    public const PROFILE_BATCH_SIZE = 50;

    private const TIME_FORMAT = 'Y-m-d\TH:i:s.v\Z';
    private const ID_PATTERN = '/^[A-Za-z0-9_-]+$/';
    private const INCREMENTAL = [Kind::Product, Kind::Order];
    private const FIRST_OFFSET = 0;

    public function __construct(private readonly RestClient $client, private readonly DateTimeImmutable $now) {}

    /** $now ends the modification window of incremental reads (Squarespace needs both ends of it). */
    public static function connect(string $apiBase, #[\SensitiveParameter] string $apiKey, HttpTransport $transport, Sleeper $sleeper, DateTimeImmutable $now): self
    {
        return new self(
            new RestClient('Squarespace', rtrim($apiBase, '/'), new SquarespaceAuthorizer($apiKey), $transport, $sleeper, new RateLimit(
                requestsPerSecond: RateLimit::perPeriod(self::REQUESTS_PER_MINUTE, self::SECONDS_PER_MINUTE),
                cooldownSeconds: self::RATE_LIMIT_COOLDOWN_SECONDS,
            )),
            $now,
        );
    }

    public function shop(): Node
    {
        $website = $this->required('/1.0/authorization/website');

        return self::identified('Website', $website->string('id'), $website->raw());
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        return match ($kind) {
            Kind::Collection => $this->listed('/1.0/commerce/store_pages', [], $cursor, 'storePages', 'StorePage'),
            Kind::Product => $this->listed('/v2/commerce/products', $this->window($since), $cursor, 'products', 'Product'),
            Kind::Customer => $this->listed('/1.0/profiles', ['sortField' => 'createdOn', 'sortDirection' => 'asc'], $cursor, 'profiles', 'Profile'),
            Kind::Discount => $this->discounts($cursor),
            Kind::Order => $this->orders($cursor, $since),
            default => throw new SourceError("Squarespace has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        $id = substr($gid, strrpos($gid, '/') + 1);
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new SourceError("Not a Squarespace identity: {$gid}");
        }

        return match ($kind) {
            Kind::Product => $this->first("/v2/commerce/products/{$id}", 'products', 'Product'),
            Kind::Customer => $this->first("/1.0/profiles/{$id}", 'profiles', 'Profile'),
            Kind::Discount => $this->discount($id),
            Kind::Order => $this->order($id),
            default => throw new SourceError("Squarespace can't read a single {$kind->value}."),
        };
    }

    public function isIncremental(Kind $kind): bool
    {
        return in_array($kind, self::INCREMENTAL, true);
    }

    /**
     * One cursor page of a list, its items identified.
     *
     * @param array<string, scalar> $filters sent only with the first page
     */
    private function listed(string $path, array $filters, ?string $cursor, string $listKey, string $type): Page
    {
        [$items, $next] = $this->cursorPage($path, $filters, $cursor, $listKey);

        return new Page(array_map(static fn(Node $item): Node => self::identified($type, $item->string('id'), $item->raw()), $items), $next !== null, $next);
    }

    /** Discounts of every status, oldest first; the cursor is the next offset. */
    private function discounts(?string $cursor): Page
    {
        $offset = $cursor === null || $cursor === '' ? self::FIRST_OFFSET : max(self::FIRST_OFFSET, (int) $cursor);
        $response = $this->required('/v1/commerce/discounts', [
            'status' => 'ALL',
            'sortBy' => 'CREATED_ON',
            'sortDirection' => 'ASCENDING',
            'offset' => $offset,
            'limit' => self::DISCOUNT_PAGE_SIZE,
        ]);
        $discounts = $response->list('discounts');
        $more = ($response->raw()['hasNextPage'] ?? false) === true && $discounts !== [];

        return new Page(
            array_map(static fn(Node $discount): Node => self::identified('Discount', $discount->string('id'), $discount->raw()), $discounts),
            $more,
            $more ? (string) ($offset + count($discounts)) : null,
        );
    }

    private function discount(string $id): ?Node
    {
        $discount = $this->client->get("/v1/commerce/discounts/{$id}")?->child('discount');

        return $discount === null ? null : self::identified('Discount', $discount->string('id'), $discount->raw());
    }

    private function orders(?string $cursor, ?DateTimeImmutable $since): Page
    {
        [$orders, $next] = $this->cursorPage('/1.0/commerce/orders', ['paymentStates' => self::ORDER_PAYMENT_STATES, ...$this->window($since)], $cursor, 'result');

        return new Page($this->assembleOrders($orders), $next !== null, $next);
    }

    private function order(string $id): ?Node
    {
        $order = $this->client->get("/1.0/commerce/orders/{$id}");

        return $order === null ? null : $this->assembleOrders([$order])[0];
    }

    /**
     * Orders identified, each with its transaction documents (every page of them) and its customer's
     * profile (null for a guest, or a customer Squarespace no longer has).
     *
     * @param list<Node> $orders
     *
     * @return list<Node>
     */
    private function assembleOrders(array $orders): array
    {
        $profiles = $this->profiles(array_map(static fn(Node $order): ?string => $order->optionalString('customerId'), $orders));

        return array_map(function (Node $order) use ($profiles): Node {
            $id = $order->string('id');
            $documents = $this->all('/1.0/commerce/transactions', ['orderId' => $id], 'documents');

            return self::identified('Order', $id, [
                ...$order->raw(),
                'transactions' => array_map(static fn(Node $document): array => $document->raw(), $documents),
                'customer_profile' => ($profiles[$order->optionalString('customerId') ?? ''] ?? null)?->raw(),
            ]);
        }, $orders);
    }

    /**
     * The profiles with these ids that Squarespace has, by id. A batch Squarespace answers with 404 (one of
     * its profiles is gone) is read again one profile at a time.
     *
     * @param list<?string> $ids
     *
     * @return array<string, Node>
     */
    private function profiles(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(?string $id): bool => $id !== null && preg_match(self::ID_PATTERN, $id) === 1)));
        sort($ids, SORT_STRING);
        $profiles = [];
        foreach (array_chunk($ids, self::PROFILE_BATCH_SIZE) as $batch) {
            $found = $this->client->get('/1.0/profiles/' . implode(',', $batch))?->list('profiles');
            if ($found === null && count($batch) > 1) {
                $found = [];
                foreach ($batch as $id) {
                    $found = [...$found, ...($this->client->get("/1.0/profiles/{$id}")?->list('profiles') ?? [])];
                }
            }
            foreach ($found ?? [] as $profile) {
                $profiles[$profile->string('id')] = $profile;
            }
        }

        return $profiles;
    }

    /**
     * One page of a cursor-paged list: its items and the next page's cursor, if Squarespace has more.
     *
     * @param array<string, scalar> $filters
     *
     * @return array{0: list<Node>, 1: ?string}
     */
    private function cursorPage(string $path, array $filters, ?string $cursor, string $listKey): array
    {
        $response = $this->required($path, $cursor === null || $cursor === '' ? $filters : ['cursor' => $cursor]);
        $pagination = $response->child('pagination');
        $next = $pagination !== null && ($pagination->raw()['hasNextPage'] ?? false) === true ? $pagination->optionalString('nextPageCursor') : null;

        return [$response->list($listKey), $next];
    }

    /**
     * Every item of a cursor-paged list.
     *
     * @param array<string, scalar> $filters
     *
     * @return list<Node>
     */
    private function all(string $path, array $filters, string $listKey): array
    {
        $items = [];
        $cursor = null;
        do {
            [$page, $cursor] = $this->cursorPage($path, $filters, $cursor, $listKey);
            $items = [...$items, ...$page];
        } while ($cursor !== null);

        return $items;
    }

    /** The first object of a by-id list response, identified, or null when Squarespace doesn't have it. */
    private function first(string $path, string $listKey, string $type): ?Node
    {
        $object = $this->client->get($path)?->list($listKey)[0] ?? null;

        return $object === null ? null : self::identified($type, $object->string('id'), $object->raw());
    }

    /** @return array<string, string> the modification window since $since (none for a full read) */
    private function window(?DateTimeImmutable $since): array
    {
        return $since === null ? [] : ['modifiedAfter' => self::time($since), 'modifiedBefore' => self::time($this->now)];
    }

    /** @param array<string, scalar> $query */
    private function required(string $path, array $query = []): Node
    {
        return $this->client->get($path, $query) ?? throw new SourceError("Squarespace has no {$path} for this site.");
    }

    private static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, string $id, array $raw): Node
    {
        $gid = Identity::of(SquarespaceAdapter::ID, $type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
