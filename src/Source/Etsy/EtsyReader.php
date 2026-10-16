<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy;

use DateTimeImmutable;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;

/**
 * Reads the connected shop from Etsy's Open API v3 (offset paging, 100 per page). Every node carries its
 * identity as `id` (`gid://etsy/<Type>/<id>`). Listings are read in every state Etsy keeps (one state
 * after another; Etsy can't filter them by update time). Receipts and reviews are incremental. Customers
 * are the buyers on receipts: Etsy exposes only a buyer's id and email, which don't vary per receipt.
 */
final class EtsyReader implements SourceReader
{
    public const PAGE_SIZE = 100;
    public const LISTING_STATES = ['active', 'inactive', 'sold_out', 'draft', 'expired'];
    public const LISTING_INCLUDES = ['Images', 'Inventory', 'Videos', 'Translations', 'Shipping', 'Personalization'];

    private const API = '/v3/application';
    private const CURSOR_SEPARATOR = ':';
    private const INCREMENTAL = [Kind::Customer, Kind::Order, Kind::Review];

    private ?Node $ownerShop = null;

    public function __construct(private readonly EtsyClient $client) {}

    public function shop(): Node
    {
        $shop = $this->ownerShop();
        $policies = $this->required("/shops/{$this->shopId()}/policies/return");

        return self::identified('Shop', $shop->int('shop_id'), [...$shop->raw(), 'return_policies' => $policies->raw()['results'] ?? []]);
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        $shop = "/shops/{$this->shopId()}";

        return match ($kind) {
            Kind::Collection => $this->list("{$shop}/sections", [], null, 'Section', 'shop_section_id'),
            Kind::Product => $this->listings($cursor),
            Kind::Order => $this->list("{$shop}/receipts", self::receiptQuery($since), $cursor, 'Receipt', 'receipt_id'),
            Kind::Customer => $this->buyers($this->list("{$shop}/receipts", self::receiptQuery($since), $cursor, 'Receipt', 'receipt_id')),
            Kind::Review => $this->list("{$shop}/reviews", $since === null ? [] : ['min_created' => $since->getTimestamp()], $cursor, 'Review', 'transaction_id'),
            default => throw new SourceError("Etsy has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        $id = (int) substr($gid, strrpos($gid, '/') + 1);
        [$path, $query, $type, $field] = match ($kind) {
            Kind::Product => ["/listings/{$id}", ['includes' => self::LISTING_INCLUDES], 'Listing', 'listing_id'],
            Kind::Order => ["/shops/{$this->shopId()}/receipts/{$id}", [], 'Receipt', 'receipt_id'],
            Kind::Collection => ["/shops/{$this->shopId()}/sections/{$id}", [], 'Section', 'shop_section_id'],
            default => throw new SourceError("Etsy can't read a single {$kind->value}."),
        };
        $node = $this->client->get(self::API . $path, $query);

        return $node === null ? null : self::identified($type, $node->int($field), $node->raw());
    }

    public function isIncremental(Kind $kind): bool
    {
        return in_array($kind, self::INCREMENTAL, true);
    }

    /** Listings, one state after another; the cursor is "<state index>:<offset>". */
    private function listings(?string $cursor): Page
    {
        [$state, $offset] = $cursor === null ? [0, 0] : array_map(intval(...), explode(self::CURSOR_SEPARATOR, $cursor, 2));
        $state = min($state, count(self::LISTING_STATES) - 1);
        $page = $this->list(
            "/shops/{$this->shopId()}/listings",
            ['state' => self::LISTING_STATES[$state], 'includes' => self::LISTING_INCLUDES],
            (string) $offset,
            'Listing',
            'listing_id',
        );
        if ($page->hasNextPage) {
            return new Page($page->nodes, true, $state . self::CURSOR_SEPARATOR . $page->endCursor);
        }
        $next = $state + 1;

        return new Page($page->nodes, $next < count(self::LISTING_STATES), $next . self::CURSOR_SEPARATOR . '0');
    }

    /**
     * One page of an offset-paged collection; the cursor is the next offset.
     *
     * @param array<string, scalar|list<string>> $query
     */
    private function list(string $path, array $query, ?string $cursor, string $type, string $idField): Page
    {
        $offset = $cursor === null ? 0 : (int) $cursor;
        $response = $this->required($path, [...$query, 'limit' => self::PAGE_SIZE, 'offset' => $offset]);
        $nodes = array_map(
            static fn(Node $item): Node => self::identified($type, $item->int($idField), $item->raw()),
            $response->list('results'),
        );
        $next = $offset + count($nodes);
        $more = $nodes !== [] && $next < $response->int('count');

        return new Page($nodes, $more, $more ? (string) $next : null);
    }

    /** Each receipt's buyer (once per page), as an identity and an email; guest receipts have no buyer. */
    private function buyers(Page $receipts): Page
    {
        $buyers = [];
        foreach ($receipts->nodes as $receipt) {
            $userId = $receipt->optionalInt('buyer_user_id');
            if ($userId === null || $userId < 1) {
                continue;
            }
            $buyers[$userId] ??= self::identified('User', $userId, [
                'buyer_user_id' => $userId,
                'buyer_email' => $receipt->optionalString('buyer_email'),
            ]);
        }

        return new Page(array_values($buyers), $receipts->hasNextPage, $receipts->endCursor);
    }

    /** @return array<string, scalar> receipts in creation order, optionally only those changed since $since */
    private static function receiptQuery(?DateTimeImmutable $since): array
    {
        $query = ['sort_on' => 'created', 'sort_order' => 'asc'];
        if ($since !== null) {
            $query['min_last_modified'] = $since->getTimestamp();
        }

        return $query;
    }

    private function ownerShop(): Node
    {
        return $this->ownerShop ??= $this->required("/users/{$this->client->userId()}/shops", [], false);
    }

    private function shopId(): int
    {
        return $this->ownerShop()->int('shop_id');
    }

    /** @param array<string, scalar|list<string>> $query */
    private function required(string $path, array $query = [], bool $inShop = true): Node
    {
        return $this->client->get(self::API . $path, $query)
            ?? throw new SourceError($inShop ? "Etsy has no {$path} for this shop." : 'The connected Etsy account has no shop.');
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, int $id, array $raw): Node
    {
        $gid = Identity::of(EtsyAdapter::ID, $type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
