<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Ecwid;

use DateTimeImmutable;
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
 * Reads a store from Ecwid's REST API v3 (docs.ecwid.com/api-reference/rest-api): `<host>/api/v3/<store id>/
 * <resource>`, every list a `{total, count, offset, limit, items}` page read by `offset` and `limit` (at most
 * 100). Every node carries its identity as `id` (`gid://ecwid/<Type>/<id>`). Products arrive with their
 * variations (`combinations`) and images, and carry the store's weight unit; orders arrive with their items,
 * refunds and shipments, and carry the store's currency; redirects carry the store's URL. Products,
 * customers, discount coupons, orders and reviews are incremental (`updatedFrom`, a UNIX timestamp, so no
 * zone margin is needed); categories and redirects are read in full. Ecwid also lists deleted ids
 * (`/<entity>/deleted`), which isn't read: full runs already retire whatever Ecwid no longer has.
 */
final class EcwidReader implements SourceReader
{
    public const API_HOST = 'https://app.ecwid.com';
    public const API_PATH = '/api/v3/';
    /** Lists take at most (and by default) 100 per page. */
    public const PAGE_SIZE = 100;
    public const SINCE_FILTER = 'updatedFrom';
    /**
     * Ecwid accepts up to 600 requests per minute per token; past that every request is refused with a 429
     * and a `Retry-After` cool-down (docs.ecwid.com/api-reference/rest-api/rest-api-overview). Requests are
     * paced under it (one every 0.1 seconds) and a 429 waits out its `Retry-After`. The page names no
     * rate-limit headers, so pacing is by rate alone. A token Ecwid keeps rejecting gets blocked for longer,
     * which is why a rejected token stops the run at once (CredentialsRejected).
     */
    public const REQUESTS_PER_MINUTE = 600;
    public const SECONDS_PER_MINUTE = 60;

    private const STORE_ID_PATTERN = '/^\d+$/';
    private const FIRST_OFFSET = 0;
    private const INCREMENTAL = [Kind::Product, Kind::Customer, Kind::Discount, Kind::Order, Kind::Review];
    /** Kinds read singly at `<resource path>/<id>` (reviews are found by the reviews search). */
    private const SINGLY_READABLE = [Kind::Collection, Kind::Product, Kind::Customer, Kind::Order];
    /**
     * Every list the reader pages: kind → [resource path, identity type, query sent with every request].
     * Products ask for clean URLs (no `#!` hashbang), so their URLs are storefront paths; disabled categories
     * are asked for too.
     */
    private const LISTS = [
        'collection' => ['/categories', 'Category', ['hidden_categories' => true]],
        'product' => ['/products', 'Product', ['cleanUrls' => true]],
        'customer' => ['/customers', 'Customer', []],
        'discount' => ['/discount_coupons', 'DiscountCoupon', []],
        'order' => ['/orders', 'Order', []],
        'url_redirect' => ['/instant-site/redirects', 'Redirect', []],
        'review' => ['/reviews', 'Review', []],
    ];
    /** The query a single read sends: a product asks for its clean URL, as the list does. */
    private const SINGLE_QUERY = ['product' => ['cleanUrls' => true]];
    private const REVIEW_FILTER = 'reviewId';

    private ?Node $profile = null;

    public function __construct(private readonly RestClient $client) {}

    public static function connect(string $apiHost, string $storeId, #[\SensitiveParameter] string $secretToken, HttpTransport $transport, Sleeper $sleeper): self
    {
        if (preg_match(self::STORE_ID_PATTERN, $storeId) !== 1) {
            throw new SourceError('Ecwid is not configured: enter the store ID (a number).');
        }
        $authorizer = new EcwidAuthorizer($secretToken);
        $rateLimit = new RateLimit(requestsPerSecond: RateLimit::perPeriod(self::REQUESTS_PER_MINUTE, self::SECONDS_PER_MINUTE));

        return new self(new RestClient('Ecwid', rtrim($apiHost, '/') . self::API_PATH . $storeId, $authorizer, $transport, $sleeper, $rateLimit));
    }

    public function shop(): Node
    {
        $profile = $this->profile();

        return self::identified('Shop', $profile->requiredChild('generalInfo')->int('storeId'), $profile->raw());
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        [$path, $type, $query] = self::LISTS[$kind->value] ?? throw new SourceError("Ecwid has no {$kind->value}.");
        if ($since !== null && $this->isIncremental($kind)) {
            $query[self::SINCE_FILTER] = $since->getTimestamp();
        }
        $offset = self::offset($cursor);
        $response = $this->required($path, [...$query, 'offset' => $offset, 'limit' => self::PAGE_SIZE]);
        $items = $response->list('items');
        $next = $offset + count($items);
        $more = $items !== [] && $next < $response->int('total');

        return new Page(array_map(fn(Node $item): Node => $this->assemble($kind, $type, $item), $items), $more, $more ? (string) $next : null);
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        $id = substr($gid, strrpos($gid, '/') + 1);
        if ($kind === Kind::Review) {
            return $this->review($id);
        }
        [$path, $type] = self::LISTS[$kind->value] ?? throw new SourceError("Ecwid has no {$kind->value}.");
        if (!in_array($kind, self::SINGLY_READABLE, true)) {
            throw new SourceError("Ecwid can't read a single {$kind->value}.");
        }
        if ($kind !== Kind::Order && preg_match(self::STORE_ID_PATTERN, $id) !== 1) {
            throw new SourceError("Not an Ecwid identity: {$gid}");
        }
        $item = $this->client->get($path . '/' . rawurlencode($id), self::SINGLE_QUERY[$kind->value] ?? []);

        return $item === null ? null : $this->assemble($kind, $type, $item);
    }

    public function isIncremental(Kind $kind): bool
    {
        return in_array($kind, self::INCREMENTAL, true);
    }

    /** A review, found by the reviews search (Ecwid documents no single-review read). */
    private function review(string $id): ?Node
    {
        foreach ($this->required('/reviews', [self::REVIEW_FILTER => $id])->list('items') as $review) {
            if (self::sourceId($review) === $id) {
                return self::identified('Review', $id, $review->raw());
            }
        }

        return null;
    }

    /** An item with its identity and what its mapper needs from the store profile. */
    private function assemble(Kind $kind, string $type, Node $item): Node
    {
        $raw = $item->raw();
        $formats = fn(): Node => $this->profile()->requiredChild('formatsAndUnits');
        $raw = match ($kind) {
            Kind::Product => [...$raw, 'weight_unit' => $formats()->optionalString('weightUnit')],
            Kind::Order => [...$raw, 'currency' => $formats()->string('currency')],
            Kind::UrlRedirect => [...$raw, 'store_url' => $this->profile()->requiredChild('generalInfo')->optionalString('storeUrl')],
            default => $raw,
        };

        return self::identified($type, self::sourceId($item), $raw);
    }

    private function profile(): Node
    {
        return $this->profile ??= $this->required('/profile');
    }

    /** @param array<string, scalar> $query */
    private function required(string $path, array $query = []): Node
    {
        return $this->client->get($path, $query) ?? throw new SourceError("Ecwid has no {$path} for this store.");
    }

    private static function offset(?string $cursor): int
    {
        return $cursor === null || $cursor === '' ? self::FIRST_OFFSET : max(self::FIRST_OFFSET, (int) $cursor);
    }

    /** An item's Ecwid id: a number for most objects, a string for an order (with its prefix) or a redirect. */
    private static function sourceId(Node $item): string
    {
        $id = $item->raw()['id'] ?? null;
        if (is_int($id) || (is_string($id) && $id !== '')) {
            return (string) $id;
        }

        throw new SourceError('An Ecwid object has no id.');
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, int|string $id, array $raw): Node
    {
        $gid = Identity::of(EcwidAdapter::ID, $type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
