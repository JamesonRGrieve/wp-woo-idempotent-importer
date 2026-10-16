<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\SquareOnline;

use DateTimeImmutable;
use DateTimeZone;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;

/**
 * Reads the seller's account from the Square API (cursor paging everywhere). Every node carries its
 * identity as `id` (`gid://square_online/<Type>/<Square id>`). Catalog items arrive with the objects they
 * reference — images, categories and item options — and their in-stock counts at the main location (the
 * location Square Online sells from), injected as `image_objects`, `category_objects`, `option_objects`
 * and `inventory_counts`. Orders are searched location by location (Square takes at most ten per search)
 * and carry `variation_items` (each line's variation id → its item id), since a line names only the
 * variation. Every kind is incremental: catalog objects by `begin_time` (deleted ones included, so they are
 * retired, never re-created), stock by `updated_after` (the items whose counts changed are re-read),
 * customers and orders by `updated_at`.
 */
final class SquareOnlineReader implements SourceReader
{
    public const API_BASE = 'https://connect.squareup.com';
    /** SearchCatalogObjects takes up to 1000; 100 keeps one page's follow-up requests small. */
    public const CATALOG_PAGE_SIZE = 100;
    /** ListCustomers / SearchCustomers take 1–100. */
    public const CUSTOMER_PAGE_SIZE = 100;
    /** SearchOrders takes up to 1000 (default 500). */
    public const ORDER_PAGE_SIZE = 100;
    /** SearchOrders takes at most ten location ids. */
    public const LOCATIONS_PER_ORDER_SEARCH = 10;
    /** Draft orders are unsubmitted carts and API drafts, never part of the store's history. */
    public const ORDER_STATES = ['OPEN', 'COMPLETED', 'CANCELED'];
    public const STOCK_STATE = 'IN_STOCK';

    private const ME = 'me';
    private const ACTIVE = 'ACTIVE';
    private const PHASE_ITEMS = 'items';
    private const PHASE_STOCK = 'stock';
    private const CURSOR_SEPARATOR = ':';
    private const RFC3339 = 'Y-m-d\TH:i:s\Z';
    private const TYPES = ['ITEM' => 'Item', 'CATEGORY' => 'Category'];

    private ?Node $merchant = null;
    /** @var list<Node>|null */
    private ?array $locations = null;

    public function __construct(private readonly RestClient $client) {}

    public static function connect(string $apiBase, #[\SensitiveParameter] string $accessToken, HttpTransport $transport, Sleeper $sleeper): self
    {
        return new self(new RestClient('Square', $apiBase, new SquareOnlineAuthorizer($accessToken), $transport, $sleeper));
    }

    public function shop(): Node
    {
        $merchant = $this->merchant();
        $sites = $this->required('/v2/sites')->list('sites');

        return self::identified('Merchant', $merchant->string('id'), [
            ...$merchant->raw(),
            'locations' => array_map(static fn(Node $location): array => $location->raw(), $this->locations()),
            'sites' => array_map(static fn(Node $site): array => $site->raw(), $sites),
        ]);
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        return match ($kind) {
            Kind::Collection => $this->categories($cursor, $since),
            Kind::Product => $this->items($cursor, $since),
            Kind::Customer => $this->customers($cursor, $since),
            Kind::Order => $this->orders($cursor, $since),
            default => throw new SourceError("Square Online has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        $id = rawurlencode(substr($gid, strrpos($gid, '/') + 1));

        return match ($kind) {
            Kind::Product => $this->catalogObject($id, 'ITEM'),
            Kind::Collection => $this->catalogObject($id, 'CATEGORY'),
            Kind::Customer => $this->one("/v2/customers/{$id}", 'customer', 'Customer'),
            Kind::Order => $this->orderById($id),
            default => throw new SourceError("Square Online can't read a single {$kind->value}."),
        };
    }

    public function isIncremental(Kind $kind): bool
    {
        return in_array($kind, [Kind::Collection, Kind::Product, Kind::Customer, Kind::Order], true);
    }

    /** The location Square Online sells from: the seller's main location, else its first active one. */
    public function mainLocationId(): string
    {
        $main = $this->merchant()->optionalString('main_location_id');
        if ($main !== null) {
            return $main;
        }
        foreach ($this->locations() as $location) {
            if ($location->optionalString('status') === self::ACTIVE) {
                return $location->string('id');
            }
        }

        throw new SourceError('The Square account has no active location.');
    }

    private function categories(?string $cursor, ?DateTimeImmutable $since): Page
    {
        $search = $this->catalogSearch('CATEGORY', $cursor, $since);
        $related = self::byId($search->list('related_objects'));
        $categories = $search->list('objects');
        $related = $this->withMissing($related, self::references($categories));
        $nodes = array_map(fn(Node $category): Node => $this->assembled($category, $related, []), $categories);
        $next = $search->optionalString('cursor');

        return new Page($nodes, $next !== null, $next);
    }

    /**
     * Items. A full run reads every item; an incremental run reads the items changed since $since (deleted
     * ones included), then the items whose stock changed since then. The cursor is "<phase>:<Square cursor>".
     */
    private function items(?string $cursor, ?DateTimeImmutable $since): Page
    {
        [$phase, $squareCursor] = $cursor === null ? [self::PHASE_ITEMS, ''] : array_pad(explode(self::CURSOR_SEPARATOR, $cursor, 2), 2, '');
        if ($phase === self::PHASE_STOCK && $since !== null) {
            return $this->restocked($squareCursor === '' ? null : $squareCursor, $since);
        }
        $search = $this->catalogSearch('ITEM', $squareCursor === '' ? null : $squareCursor, $since);
        $nodes = $this->assembleItems($search->list('objects'), self::byId($search->list('related_objects')));
        $next = $search->optionalString('cursor');
        if ($next !== null) {
            return new Page($nodes, true, self::PHASE_ITEMS . self::CURSOR_SEPARATOR . $next);
        }

        return new Page($nodes, $since !== null, $since === null ? null : self::PHASE_STOCK . self::CURSOR_SEPARATOR);
    }

    /** One page of stock counts changed since $since at the main location, as the items they belong to. */
    private function restocked(?string $cursor, DateTimeImmutable $since): Page
    {
        $counts = $this->required('/v2/inventory/counts/batch-retrieve', [
            'location_ids' => [$this->mainLocationId()],
            'states' => [self::STOCK_STATE],
            'updated_after' => self::time($since),
            ...self::cursor($cursor),
        ]);
        $variationIds = self::unique(array_map(static fn(Node $count): ?string => $count->optionalString('catalog_object_id'), $counts->list('counts')));
        $itemIds = [];
        if ($variationIds !== []) {
            foreach ($this->batch($variationIds, false)->list('objects') as $variation) {
                $itemIds[] = $variation->child('item_variation_data')?->optionalString('item_id');
            }
        }
        $itemIds = self::unique($itemIds);
        $nodes = [];
        if ($itemIds !== []) {
            $items = $this->batch($itemIds, true);
            $nodes = $this->assembleItems($items->list('objects'), self::byId($items->list('related_objects')));
        }
        $next = $counts->optionalString('cursor');

        return new Page($nodes, $next !== null, $next === null ? null : self::PHASE_STOCK . self::CURSOR_SEPARATOR . $next);
    }

    /**
     * @param list<Node> $items
     * @param array<string, Node> $related
     *
     * @return list<Node>
     */
    private function assembleItems(array $items, array $related): array
    {
        $live = array_values(array_filter($items, static fn(Node $item): bool => !self::isDeleted($item)));
        $related = $this->withMissing($related, self::references($live));
        $counts = $this->stock($live);

        return array_map(fn(Node $item): Node => $this->assembled($item, $related, $counts), $items);
    }

    /**
     * A catalog object with the objects it references, by kind, and (for an item) its variations' counts.
     * A deleted object is passed on as Square sent it: it is retired.
     *
     * @param array<string, Node> $related
     * @param array<string, list<array<array-key, mixed>>> $counts by variation id
     */
    private function assembled(Node $object, array $related, array $counts): Node
    {
        $id = $object->string('id');
        $type = self::TYPES[$object->string('type')] ?? throw new SourceError("Square catalog object {$id} is not an item or a category.");
        if (self::isDeleted($object)) {
            return self::identified($type, $id, $object->raw());
        }
        $referenced = static fn(string $kind): array => array_values(array_map(
            static fn(Node $node): array => $node->raw(),
            array_filter(
                array_map(static fn(string $ref): ?Node => $related[$ref] ?? null, self::references([$object])),
                static fn(?Node $node): bool => $node !== null && $node->string('type') === $kind && !self::isDeleted($node),
            ),
        ));
        $extra = ['image_objects' => $referenced('IMAGE')];
        if ($type === 'Item') {
            $variationCounts = [];
            foreach ($object->child('item_data')?->list('variations') ?? [] as $variation) {
                $variationCounts = [...$variationCounts, ...($counts[$variation->string('id')] ?? [])];
            }
            $extra += [
                'category_objects' => $referenced('CATEGORY'),
                'option_objects' => $referenced('ITEM_OPTION'),
                'inventory_counts' => $variationCounts,
                'main_location_id' => $this->mainLocationId(),
            ];
        }

        return self::identified($type, $id, [...$object->raw(), ...$extra]);
    }

    /**
     * In-stock counts at the main location of every variation of these items.
     *
     * @param list<Node> $items
     *
     * @return array<string, list<array<array-key, mixed>>> by variation id
     */
    private function stock(array $items): array
    {
        $variationIds = [];
        foreach ($items as $item) {
            foreach ($item->child('item_data')?->list('variations') ?? [] as $variation) {
                $variationIds[] = $variation->string('id');
            }
        }
        $variationIds = self::unique($variationIds);
        $counts = [];
        $cursor = null;
        while ($variationIds !== []) {
            $page = $this->required('/v2/inventory/counts/batch-retrieve', [
                'catalog_object_ids' => $variationIds,
                'location_ids' => [$this->mainLocationId()],
                'states' => [self::STOCK_STATE],
                ...self::cursor($cursor),
            ]);
            foreach ($page->list('counts') as $count) {
                $counts[$count->string('catalog_object_id')][] = $count->raw();
            }
            $cursor = $page->optionalString('cursor');
            if ($cursor === null) {
                break;
            }
        }

        return $counts;
    }

    private function customers(?string $cursor, ?DateTimeImmutable $since): Page
    {
        $page = $since === null
            ? $this->client->get('/v2/customers', [
                'limit' => self::CUSTOMER_PAGE_SIZE,
                'sort_field' => 'CREATED_AT',
                'sort_order' => 'ASC',
                ...self::cursor($cursor),
            ])
            : $this->client->query('/v2/customers/search', [
                'limit' => self::CUSTOMER_PAGE_SIZE,
                'query' => [
                    'filter' => ['updated_at' => ['start_at' => self::time($since)]],
                    'sort' => ['field' => 'CREATED_AT', 'order' => 'ASC'],
                ],
                ...self::cursor($cursor),
            ]);
        $page ??= throw new SourceError('Square has no customers endpoint for this account.');
        $nodes = array_map(static fn(Node $customer): Node => self::identified('Customer', $customer->string('id'), $customer->raw()), $page->list('customers'));
        $next = $page->optionalString('cursor');

        return new Page($nodes, $next !== null, $next);
    }

    /** Orders, ten locations at a time; the cursor is "<location group>:<Square cursor>". */
    private function orders(?string $cursor, ?DateTimeImmutable $since): Page
    {
        $groups = array_chunk(self::unique(array_map(static fn(Node $location): string => $location->string('id'), $this->locations())), self::LOCATIONS_PER_ORDER_SEARCH);
        [$group, $squareCursor] = $cursor === null ? ['0', ''] : array_pad(explode(self::CURSOR_SEPARATOR, $cursor, 2), 2, '');
        $group = (int) $group;
        if (!isset($groups[$group])) {
            return new Page([], false, null);
        }
        $filter = ['state_filter' => ['states' => self::ORDER_STATES]];
        if ($since !== null) {
            $filter['date_time_filter'] = ['updated_at' => ['start_at' => self::time($since)]];
        }
        $search = $this->required('/v2/orders/search', [
            'location_ids' => $groups[$group],
            'limit' => self::ORDER_PAGE_SIZE,
            'query' => ['filter' => $filter, 'sort' => ['sort_field' => $since === null ? 'CREATED_AT' : 'UPDATED_AT', 'sort_order' => 'ASC']],
            ...self::cursor($squareCursor === '' ? null : $squareCursor),
        ]);
        $nodes = $this->withVariationItems($search->list('orders'));
        $next = $search->optionalString('cursor');
        if ($next !== null) {
            return new Page($nodes, true, $group . self::CURSOR_SEPARATOR . $next);
        }
        $following = $group + 1;

        return new Page($nodes, isset($groups[$following]), isset($groups[$following]) ? $following . self::CURSOR_SEPARATOR : null);
    }

    private function orderById(string $id): ?Node
    {
        $order = $this->client->get("/v2/orders/{$id}")?->child('order');

        return $order === null ? null : $this->withVariationItems([$order])[0];
    }

    /**
     * Orders identified, each with the item every line's variation belongs to (a line names only the
     * variation). A variation Square no longer has is left out: that line keeps its name but no product.
     *
     * @param list<Node> $orders
     *
     * @return list<Node>
     */
    private function withVariationItems(array $orders): array
    {
        $variationIds = [];
        foreach ($orders as $order) {
            foreach ($order->list('line_items') as $line) {
                $variationIds[] = $line->optionalString('catalog_object_id');
            }
        }
        $variationIds = self::unique($variationIds);
        $items = [];
        if ($variationIds !== []) {
            foreach ($this->batch($variationIds, false)->list('objects') as $variation) {
                $itemId = $variation->child('item_variation_data')?->optionalString('item_id');
                if ($itemId !== null && !self::isDeleted($variation)) {
                    $items[$variation->string('id')] = $itemId;
                }
            }
        }

        return array_map(static function (Node $order) use ($items): Node {
            $lines = [];
            foreach ($order->list('line_items') as $line) {
                $variationId = $line->optionalString('catalog_object_id');
                if ($variationId !== null && isset($items[$variationId])) {
                    $lines[$variationId] = $items[$variationId];
                }
            }

            return self::identified('Order', $order->string('id'), [...$order->raw(), 'variation_items' => $lines]);
        }, $orders);
    }

    private function catalogObject(string $id, string $type): ?Node
    {
        $response = $this->client->get("/v2/catalog/object/{$id}", ['include_related_objects' => true]);
        $object = $response?->child('object');
        if ($response === null || $object === null || $object->string('type') !== $type || self::isDeleted($object)) {
            return null;
        }
        $related = self::byId($response->list('related_objects'));

        return $type === 'ITEM'
            ? $this->assembleItems([$object], $related)[0]
            : $this->assembled($object, $this->withMissing($related, self::references([$object])), []);
    }

    private function catalogSearch(string $type, ?string $cursor, ?DateTimeImmutable $since): Node
    {
        $body = ['object_types' => [$type], 'include_related_objects' => true, 'limit' => self::CATALOG_PAGE_SIZE];
        if ($since !== null) {
            $body += ['begin_time' => self::time($since), 'include_deleted_objects' => true];
        }

        return $this->required('/v2/catalog/search', [...$body, ...self::cursor($cursor)]);
    }

    /**
     * Catalog objects by id (and, with $related, the objects they reference).
     *
     * @param list<string> $ids
     */
    private function batch(array $ids, bool $related): Node
    {
        return $this->required('/v2/catalog/batch-retrieve', ['object_ids' => $ids, 'include_related_objects' => $related]);
    }

    /**
     * $related plus any of $ids it lacks, read in one batch (Square's related objects don't include item
     * options, and may leave out variation images).
     *
     * @param array<string, Node> $related
     * @param list<string> $ids
     *
     * @return array<string, Node>
     */
    private function withMissing(array $related, array $ids): array
    {
        $missing = array_values(array_filter($ids, static fn(string $id): bool => !isset($related[$id])));

        return $missing === [] ? $related : [...$related, ...self::byId($this->batch($missing, false)->list('objects'))];
    }

    /**
     * The ids of the images, categories and item options catalog objects reference, sorted.
     *
     * @param list<Node> $objects
     *
     * @return list<string>
     */
    private static function references(array $objects): array
    {
        $ids = [];
        foreach ($objects as $object) {
            $category = $object->child('category_data');
            if ($category !== null) {
                $ids = [...$ids, ...$category->strings('image_ids')];
            }
            $item = $object->child('item_data');
            if ($item === null) {
                continue;
            }
            $ids = [...$ids, ...$item->strings('image_ids')];
            foreach ($item->list('categories') as $category) {
                $ids[] = $category->string('id');
            }
            foreach ($item->list('item_options') as $option) {
                $ids[] = $option->optionalString('item_option_id');
            }
            foreach ($item->list('variations') as $variation) {
                $ids = [...$ids, ...($variation->child('item_variation_data')?->strings('image_ids') ?? [])];
            }
        }

        return self::unique($ids);
    }

    private function one(string $path, string $field, string $type): ?Node
    {
        $object = $this->client->get($path)?->child($field);

        return $object === null ? null : self::identified($type, $object->string('id'), $object->raw());
    }

    private function merchant(): Node
    {
        return $this->merchant ??= $this->required('/v2/merchants/' . self::ME)->requiredChild('merchant');
    }

    /** @return list<Node> */
    private function locations(): array
    {
        return $this->locations ??= $this->required('/v2/locations')->list('locations');
    }

    /**
     * A GET (no body) or a JSON POST query (a body) that must answer.
     *
     * @param array<string, mixed>|null $body
     */
    private function required(string $path, ?array $body = null): Node
    {
        $response = $body === null ? $this->client->get($path) : $this->client->query($path, $body);

        return $response ?? throw new SourceError("Square has no {$path} for this account.");
    }

    /** @return array{cursor?: string} */
    private static function cursor(?string $cursor): array
    {
        return $cursor === null || $cursor === '' ? [] : ['cursor' => $cursor];
    }

    /**
     * @param list<Node> $objects
     *
     * @return array<string, Node>
     */
    private static function byId(array $objects): array
    {
        $byId = [];
        foreach ($objects as $object) {
            $byId[$object->string('id')] = $object;
        }

        return $byId;
    }

    /**
     * @param list<?string> $ids
     *
     * @return list<string> distinct, sorted, without nulls
     */
    private static function unique(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(?string $id): bool => $id !== null && $id !== '')));
        sort($ids, SORT_STRING);

        return $ids;
    }

    private static function isDeleted(Node $object): bool
    {
        return ($object->raw()['is_deleted'] ?? false) === true;
    }

    private static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::RFC3339);
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, string $id, array $raw): Node
    {
        $gid = Identity::of(SquareOnlineAdapter::ID, $type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
