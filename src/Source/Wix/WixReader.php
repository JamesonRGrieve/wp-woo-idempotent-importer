<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix;

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
 * Reads a site from Wix's REST APIs (JSON POST queries; cursor paging where Wix has it, offset paging for
 * the V1 catalog, coupons and blog categories). Every node carries its identity as `id`
 * (`gid://wix/<Type>/<id>`). The site's Stores catalog version is asked once: a V3 catalog's products
 * arrive with their variants and inventory items (injected as `variants` and `inventory_items`), a V1 catalog's
 * with their variants inline; both, and coupons, carry the catalog they came from (`catalog`). Orders
 * arrive with their payments, refunds and fulfillments (injected under those names); blog posts with the
 * site id (`blog_id`). The site's blog comes first in the Blog kind, then its categories. Contacts and
 * orders are incremental (by update time), as are a V3 catalog's categories and products; an incremental
 * product run then also re-reads the products whose inventory changed (the cursor is "<phase>:<cursor>").
 */
final class WixReader implements SourceReader
{
    public const API_BASE = 'https://www.wixapis.com';
    /** The most that most Wix queries return per page (and the most orders the transaction and fulfillment reads take). */
    public const PAGE_SIZE = 100;
    /**
     * Wix documents no request rate or rate-limit headers; a 429 carries no timing hint and its docs say to
     * wait a minute before retrying (https://dev.wix.com/docs/api-reference/articles/work-with-wix-apis/troubleshooting/troubleshooting.md).
     */
    public const RATE_LIMIT_COOLDOWN_SECONDS = 60.0;
    public const STORES_NAMESPACE = '@wix/stores';
    public const REVIEWS_NAMESPACE = 'stores';
    /** The V3 product fields read beyond the base ones (URL, HTML description, media, categories, unit, cost, info sections). */
    public const PRODUCT_FIELDS = [
        'URL', 'PLAIN_DESCRIPTION', 'MEDIA_ITEMS_INFO', 'DIRECT_CATEGORIES_INFO', 'WEIGHT_MEASUREMENT_UNIT_INFO', 'MERCHANT_DATA',
        'INFO_SECTION', 'INFO_SECTION_PLAIN_DESCRIPTION',
    ];
    public const VARIANT_FIELDS = ['MERCHANT_DATA'];
    public const CATEGORY_FIELDS = ['DESCRIPTION'];
    public const POST_FIELDSETS = ['URL', 'RICH_CONTENT', 'SEO'];
    public const BLOG_CATEGORY_FIELDSETS = ['URL'];
    public const V1_PRODUCT_OPTIONS = ['includeVariants' => true, 'includeHiddenProducts' => true, 'includeMerchantSpecificData' => true];

    private const PHASE_PRODUCTS = 'products';
    private const PHASE_STOCK = 'stock';
    private const CURSOR_SEPARATOR = ':';
    private const FILTER_TIME_FORMAT = 'Y-m-d\TH:i:s.v\Z';
    private const SINGLY_READABLE = [Kind::Product, Kind::Customer, Kind::Order];

    private ?Catalog $catalog = null;

    public function __construct(private readonly RestClient $client, private readonly string $siteId) {}

    public static function connect(string $apiBase, #[\SensitiveParameter] string $apiKey, string $siteId, HttpTransport $transport, Sleeper $sleeper): self
    {
        $authorizer = new WixAuthorizer($apiKey, $siteId);

        return new self(new RestClient('Wix', rtrim($apiBase, '/'), $authorizer, $transport, $sleeper, new RateLimit(cooldownSeconds: self::RATE_LIMIT_COOLDOWN_SECONDS)), $siteId);
    }

    public function shop(): Node
    {
        $properties = $this->client->get('/site-properties/v4/properties')?->child('properties')
            ?? throw new SourceError('Wix has no properties for this site.');

        return self::identified('Site', $this->siteId, $properties->raw());
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        return match ($kind) {
            Kind::Collection => match ($this->catalog()) {
                Catalog::V3 => $this->categories($cursor, $since),
                Catalog::V1 => $this->collections($cursor),
                Catalog::None => new Page([], false, null),
            },
            Kind::Product => match ($this->catalog()) {
                Catalog::V3 => $this->v3Products($cursor, $since),
                Catalog::V1 => $this->v1Products($cursor),
                Catalog::None => new Page([], false, null),
            },
            Kind::Customer => $this->contacts($cursor, $since),
            Kind::Discount => $this->coupons($cursor),
            Kind::Order => $this->orders($cursor, $since),
            Kind::Blog => $this->blogs($cursor),
            Kind::Article => $this->posts($cursor),
            Kind::UrlRedirect => $this->redirects(),
            Kind::Review => $this->reviews($cursor),
            default => throw new SourceError("Wix has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        if (!in_array($kind, self::SINGLY_READABLE, true)) {
            throw new SourceError("Wix can't read a single {$kind->value}.");
        }
        $id = substr($gid, strrpos($gid, '/') + 1);

        return match ($kind) {
            Kind::Product => $this->product($id),
            Kind::Customer => $this->first('/contacts/v5/contacts/query', 'query', ['filter' => ['id' => ['$in' => [$id]]]], [], 'contacts', 'Contact'),
            default => $this->order($id),
        };
    }

    public function isIncremental(Kind $kind): bool
    {
        return match ($kind) {
            Kind::Customer, Kind::Order => true,
            Kind::Collection, Kind::Product => $this->catalog() === Catalog::V3,
            default => false,
        };
    }

    /** The site's Stores catalog version, asked once. */
    public function catalog(): Catalog
    {
        if ($this->catalog === null) {
            $version = $this->client->get('/stores/v3/provision/version')?->string('catalogVersion')
                ?? throw new SourceError('Wix could not say which Stores catalog this site has.');
            $this->catalog = Catalog::tryFrom($version) ?? throw new SourceError("Wix has an unknown Stores catalog version: {$version}.");
        }

        return $this->catalog;
    }

    /** A V3 catalog's categories (of the Stores category tree, hidden ones included). */
    private function categories(?string $cursor, ?DateTimeImmutable $since): Page
    {
        [$categories, $next] = $this->cursorPage(
            '/categories/v1/categories/query',
            'query',
            self::listQuery('createdDate', $since),
            ['treeReference' => ['appNamespace' => self::STORES_NAMESPACE], 'returnNonVisibleCategories' => true, 'fields' => self::CATEGORY_FIELDS],
            'categories',
            'pagingMetadata',
            $cursor,
        );

        return self::paged(array_map(static fn(Node $category): Node => self::identified('Category', $category->string('id'), $category->raw()), $categories), $next);
    }

    /** A V1 catalog's collections. */
    private function collections(?string $cursor): Page
    {
        [$collections, $next] = $this->offsetPage('/stores-reader/v1/collections/query', [], ['includeDescription' => true], 'collections', $cursor);

        return self::paged(array_map(static fn(Node $collection): Node => self::identified('Collection', $collection->string('id'), $collection->raw()), $collections), $next);
    }

    /**
     * A V3 catalog's products (those updated since $since, on an incremental run), then on an incremental run
     * the products whose inventory changed since then: an inventory change doesn't update its product.
     */
    private function v3Products(?string $cursor, ?DateTimeImmutable $since): Page
    {
        [$phase, $wixCursor] = self::phase($cursor);
        if ($phase === self::PHASE_PRODUCTS) {
            [$products, $next] = $this->cursorPage('/stores/v3/products/query', 'query', self::listQuery('createdDate', $since), ['fields' => self::PRODUCT_FIELDS], 'products', 'pagingMetadata', $wixCursor);
            $nodes = $this->assembleV3($products);
            if ($next !== null) {
                return new Page($nodes, true, self::PHASE_PRODUCTS . self::CURSOR_SEPARATOR . $next);
            }

            return $since === null ? new Page($nodes, false, null) : new Page($nodes, true, self::PHASE_STOCK . self::CURSOR_SEPARATOR);
        }
        if ($since === null) {
            return new Page([], false, null);
        }
        [$items, $next] = $this->cursorPage('/stores/v3/inventory-items/query', 'query', self::listQuery('updatedDate', $since), [], 'inventoryItems', 'pagingMetadata', $wixCursor);
        $ids = array_values(array_unique(array_map(static fn(Node $item): string => $item->string('productId'), $items)));
        $nodes = $ids === [] ? [] : $this->assembleV3($this->allCursor('/stores/v3/products/query', 'query', ['filter' => ['id' => ['$in' => $ids]]], ['fields' => self::PRODUCT_FIELDS], 'products', 'pagingMetadata'));

        return self::paged($nodes, $next === null ? null : self::PHASE_STOCK . self::CURSOR_SEPARATOR . $next);
    }

    private function product(string $id): ?Node
    {
        if ($this->catalog() === Catalog::V1) {
            $product = $this->required('/stores-reader/v1/products/query', ['query' => ['filter' => self::v1Filter(['id' => $id])], ...self::V1_PRODUCT_OPTIONS])->list('products')[0] ?? null;

            return $product === null ? null : self::v1Product($product);
        }
        $products = $this->allCursor('/stores/v3/products/query', 'query', ['filter' => ['id' => ['$in' => [$id]]]], ['fields' => self::PRODUCT_FIELDS], 'products', 'pagingMetadata');

        return $this->assembleV3($products)[0] ?? null;
    }

    /**
     * V3 products with their variants and inventory items (every page of both, read for the whole page).
     *
     * @param list<Node> $products
     *
     * @return list<Node>
     */
    private function assembleV3(array $products): array
    {
        $ids = array_map(static fn(Node $product): string => $product->string('id'), $products);
        if ($ids === []) {
            return [];
        }
        $variants = [];
        foreach ($this->allCursor('/stores/v3/products/query-variants', 'query', ['filter' => ['productData.productId' => ['$in' => $ids]]], ['fields' => self::VARIANT_FIELDS], 'variants', 'pagingMetadata') as $variant) {
            $variants[$variant->requiredChild('productData')->string('productId')][] = $variant->raw();
        }
        $inventory = [];
        foreach ($this->allCursor('/stores/v3/inventory-items/query', 'query', ['filter' => ['productId' => ['$in' => $ids]]], [], 'inventoryItems', 'pagingMetadata') as $item) {
            $inventory[$item->string('productId')][] = $item->raw();
        }

        return array_map(static fn(Node $product): Node => self::identified('Product', $product->string('id'), [
            ...$product->raw(),
            'catalog' => Catalog::V3->value,
            'variants' => $variants[$product->string('id')] ?? [],
            'inventory_items' => $inventory[$product->string('id')] ?? [],
        ]), $products);
    }

    /** A V1 catalog's products, hidden ones included, with their variants and merchant data (cost). */
    private function v1Products(?string $cursor): Page
    {
        [$products, $next] = $this->offsetPage('/stores-reader/v1/products/query', [], self::V1_PRODUCT_OPTIONS, 'products', $cursor);

        return self::paged(array_map(self::v1Product(...), $products), $next);
    }

    private static function v1Product(Node $product): Node
    {
        return self::identified('Product', $product->string('id'), [...$product->raw(), 'catalog' => Catalog::V1->value]);
    }

    private function contacts(?string $cursor, ?DateTimeImmutable $since): Page
    {
        [$contacts, $next] = $this->cursorPage('/contacts/v5/contacts/query', 'query', self::listQuery('createdDate', $since), [], 'contacts', 'pagingMetadata', $cursor);

        return self::paged(array_map(static fn(Node $contact): Node => self::identified('Contact', $contact->string('id'), $contact->raw()), $contacts), $next);
    }

    /** Coupons, with the catalog they target products and collections of. */
    private function coupons(?string $cursor): Page
    {
        [$coupons, $next] = $this->offsetPage('/stores/v2/coupons/query', [], [], 'coupons', $cursor);
        $catalog = $this->catalog()->value;

        return self::paged(array_map(static fn(Node $coupon): Node => self::identified('Coupon', $coupon->string('id'), [...$coupon->raw(), 'catalog' => $catalog]), $coupons), $next);
    }

    private function orders(?string $cursor, ?DateTimeImmutable $since): Page
    {
        [$orders, $next] = $this->cursorPage('/ecom/v1/orders/search', 'search', self::listQuery('createdDate', $since), [], 'orders', 'metadata', $cursor);

        return self::paged($this->assembleOrders($orders), $next);
    }

    private function order(string $id): ?Node
    {
        $order = $this->required('/ecom/v1/orders/search', ['search' => ['filter' => ['id' => ['$eq' => $id]], 'cursorPaging' => ['limit' => self::PAGE_SIZE]]])->list('orders')[0] ?? null;

        return $order === null ? null : $this->assembleOrders([$order])[0];
    }

    /**
     * Orders with their payments and refunds and their fulfillments, each read for the whole page (a page
     * is at most the 100 orders those methods take).
     *
     * @param list<Node> $orders
     *
     * @return list<Node>
     */
    private function assembleOrders(array $orders): array
    {
        $ids = array_map(static fn(Node $order): string => $order->string('id'), $orders);
        if ($ids === []) {
            return [];
        }
        $transactions = [];
        foreach ($this->required('/ecom/v1/payments/list-by-ids', ['orderIds' => $ids])->list('orderTransactions') as $entry) {
            $transactions[$entry->string('orderId')] = $entry;
        }
        $fulfillments = [];
        foreach ($this->required('/ecom/v1/fulfillments/list-by-ids', ['orderIds' => $ids])->list('ordersWithFulfillments') as $entry) {
            $fulfillments[$entry->string('orderId')] = array_map(static fn(Node $fulfillment): array => $fulfillment->raw(), $entry->list('fulfillments'));
        }

        return array_map(static function (Node $order) use ($transactions, $fulfillments): Node {
            $id = $order->string('id');
            $entry = $transactions[$id] ?? null;

            return self::identified('Order', $id, [
                ...$order->raw(),
                'payments' => array_map(static fn(Node $payment): array => $payment->raw(), $entry?->list('payments') ?? []),
                'refunds' => array_map(static fn(Node $refund): array => $refund->raw(), $entry?->list('refunds') ?? []),
                'fulfillments' => $fulfillments[$id] ?? [],
            ]);
        }, $orders);
    }

    /** The site's blog first (on the first page), then the blog's categories. */
    private function blogs(?string $cursor): Page
    {
        [$categories, $next] = $this->offsetPage('/blog/v3/categories/query', [], ['fieldsets' => self::BLOG_CATEGORY_FIELDSETS], 'categories', $cursor);
        $nodes = array_map(static fn(Node $category): Node => self::identified('BlogCategory', $category->string('id'), $category->raw()), $categories);
        if ($cursor === null) {
            array_unshift($nodes, self::identified('Blog', $this->siteId, []));
        }

        return self::paged($nodes, $next);
    }

    /** Published blog posts, with their content, URL and SEO, oldest first. */
    private function posts(?string $cursor): Page
    {
        [$posts, $next] = $this->cursorPage('/blog/v3/posts/query', 'query', ['sort' => self::oldestFirst('firstPublishedDate')], ['fieldsets' => self::POST_FIELDSETS], 'posts', 'pagingMetadata', $cursor);
        $siteId = $this->siteId;

        return self::paged(array_map(static fn(Node $post): Node => self::identified('Post', $post->string('id'), [...$post->raw(), 'blog_id' => $siteId]), $posts), $next);
    }

    /** Every redirect on the site (Wix returns them all at once). */
    private function redirects(): Page
    {
        $redirects = $this->client->get('/seo-redirects-service/v1/redirects')?->list('redirects')
            ?? throw new SourceError('Wix has no redirects list for this site.');

        return new Page(array_map(static fn(Node $redirect): Node => self::identified('Redirect', $redirect->string('id'), $redirect->raw()), $redirects), false, null);
    }

    /** Wix Stores product reviews, unpublished ones included. */
    private function reviews(?string $cursor): Page
    {
        [$reviews, $next] = $this->cursorPage(
            '/reviews/v1/reviews/query',
            'query',
            ['filter' => ['namespace' => self::REVIEWS_NAMESPACE], 'sort' => self::oldestFirst('createdDate')],
            ['returnPrivateReviews' => true],
            'reviews',
            'metadata',
            $cursor,
        );

        return self::paged(array_map(static fn(Node $review): Node => self::identified('Review', $review->string('id'), $review->raw()), $reviews), $next);
    }

    /**
     * One page of a cursor-paged query and the next page's cursor. The first page sends $query (its filter
     * and sort) with the page size; later pages send only the cursor, as Wix requires.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $options the request's other top-level fields
     *
     * @return array{0: list<Node>, 1: ?string}
     */
    private function cursorPage(string $path, string $envelope, array $query, array $options, string $listKey, string $metadataKey, ?string $cursor): array
    {
        $paging = $cursor === null || $cursor === '' ? ['limit' => self::PAGE_SIZE] : ['limit' => self::PAGE_SIZE, 'cursor' => $cursor];
        $response = $this->required($path, [$envelope => isset($paging['cursor']) ? ['cursorPaging' => $paging] : [...$query, 'cursorPaging' => $paging], ...$options]);
        $metadata = $response->child($metadataKey);
        $next = $metadata?->child('cursors')?->optionalString('next');
        $hasNext = $metadata?->raw()['hasNext'] ?? null;

        return [$response->list($listKey), $hasNext === false ? null : $next];
    }

    /**
     * Every item of a cursor-paged query.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $options
     *
     * @return list<Node>
     */
    private function allCursor(string $path, string $envelope, array $query, array $options, string $listKey, string $metadataKey): array
    {
        $items = [];
        $cursor = null;
        do {
            [$page, $cursor] = $this->cursorPage($path, $envelope, $query, $options, $listKey, $metadataKey, $cursor);
            $items = [...$items, ...$page];
        } while ($cursor !== null);

        return $items;
    }

    /**
     * One page of an offset-paged query (`query.paging`) and the next offset, if this page was full.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $options
     *
     * @return array{0: list<Node>, 1: ?string}
     */
    private function offsetPage(string $path, array $query, array $options, string $listKey, ?string $cursor): array
    {
        $offset = $cursor !== null && preg_match('/^\d+$/', $cursor) === 1 ? (int) $cursor : 0;
        $items = $this->required($path, ['query' => [...$query, 'paging' => ['limit' => self::PAGE_SIZE, 'offset' => $offset]], ...$options])->list($listKey);

        return [$items, count($items) >= self::PAGE_SIZE ? (string) ($offset + count($items)) : null];
    }

    /**
     * The first object of a cursor-paged query, identified, or null when there is none.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $options
     */
    private function first(string $path, string $envelope, array $query, array $options, string $listKey, string $type): ?Node
    {
        $object = $this->cursorPage($path, $envelope, $query, $options, $listKey, 'pagingMetadata', null)[0][0] ?? null;

        return $object === null ? null : self::identified($type, $object->string('id'), $object->raw());
    }

    /** @param array<string, mixed> $body */
    private function required(string $path, array $body): Node
    {
        return $this->client->query($path, $body) ?? throw new SourceError("Wix has no {$path} for this site.");
    }

    /**
     * A list's first-page query: oldest first by $sortField, and only what was updated since $since.
     *
     * @return array<string, mixed>
     */
    private static function listQuery(string $sortField, ?DateTimeImmutable $since): array
    {
        $query = ['sort' => self::oldestFirst($sortField)];
        if ($since !== null) {
            $query['filter'] = ['updatedDate' => ['$gt' => $since->setTimezone(new DateTimeZone('UTC'))->format(self::FILTER_TIME_FORMAT)]];
        }

        return $query;
    }

    /** @return list<array{fieldName: string, order: string}> */
    private static function oldestFirst(string $field): array
    {
        return [['fieldName' => $field, 'order' => 'ASC']];
    }

    /**
     * A V1 query's filter, which V1 takes as a JSON string.
     *
     * @param array<string, string> $filter
     */
    private static function v1Filter(array $filter): string
    {
        return json_encode($filter, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The phase a product cursor is in and the Wix cursor within it.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function phase(?string $cursor): array
    {
        if ($cursor === null) {
            return [self::PHASE_PRODUCTS, null];
        }
        [$phase, $wixCursor] = array_pad(explode(self::CURSOR_SEPARATOR, $cursor, 2), 2, '');

        return [$phase === self::PHASE_STOCK ? self::PHASE_STOCK : self::PHASE_PRODUCTS, $wixCursor === '' ? null : $wixCursor];
    }

    /** @param list<Node> $nodes */
    private static function paged(array $nodes, ?string $next): Page
    {
        return new Page($nodes, $next !== null, $next);
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, string $id, array $raw): Node
    {
        $gid = Identity::of(WixAdapter::ID, $type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
