<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RateLimit;
use IdempotentImporter\Http\ResetFormat;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;

/**
 * Reads a store from BigCommerce's REST Management API (page-numbered paging everywhere; v3 lists say how
 * many pages there are, v2 lists end at a short page). Every node carries its identity as `id`
 * (`gid://bigcommerce/<Type>/<id>`). Products arrive with their variants, brand name and the store's weight
 * unit (injected as `variants`, `brand` and `weight_units`); orders with their lines, shipping addresses,
 * taxes, coupons, shipments and refunds (injected under those names); coupon promotions with their codes;
 * blog posts with the store's URL (their thumbnails are store paths). Coupons come before promotions in one
 * Discount run (the cursor is "<phase>:<page>"). Products, customers and orders are incremental (by
 * modification time); BigCommerce has no such filter for the other kinds, which are read in full.
 */
final class BigCommerceReader implements SourceReader
{
    public const API_BASE = 'https://api.bigcommerce.com';
    /** v2 and v3 lists take up to 250 per page. */
    public const PAGE_SIZE = 250;
    /** Each product is followed by a read of its variants, so product pages are kept small. */
    public const PRODUCT_PAGE_SIZE = 50;
    public const PRODUCT_INCLUDES = 'images,custom_fields';
    public const CUSTOMER_INCLUDES = 'addresses,storecredit,attributes,formfields';
    /** The v2 order sub-resources read for every order, each injected under its own name. */
    public const ORDER_PARTS = ['products', 'shipping_addresses', 'taxes', 'coupons', 'shipments'];
    public const BLOG_PAGE_TYPE = 'blog';
    public const COUPON_PROMOTION = 'COUPON';
    /**
     * BigCommerce's quota is per plan, reported on every response: requests left in the window and the
     * milliseconds until it resets (docs.bigcommerce.com/docs/start/best-practices/api-rate-limits).
     */
    public const REQUESTS_LEFT_HEADER = 'X-Rate-Limit-Requests-Left';
    public const RESET_MS_HEADER = 'X-Rate-Limit-Time-Reset-Ms';

    private const PHASE_COUPONS = 'coupons';
    private const PHASE_PROMOTIONS = 'promotions';
    private const CURSOR_SEPARATOR = ':';
    private const FIRST_PAGE = 1;
    private const STORE_HASH_PATTERN = '/^[a-z0-9]+$/';
    private const INCREMENTAL = [Kind::Product, Kind::Customer, Kind::Order];
    private const SINGLY_READABLE = [Kind::Collection, Kind::Product, Kind::Customer, Kind::Discount, Kind::Order, Kind::Page, Kind::Article, Kind::UrlRedirect, Kind::GiftCard];

    private ?Node $store = null;

    public function __construct(private readonly RestClient $client, private readonly string $storeHash) {}

    public static function connect(string $apiBase, string $storeHash, #[\SensitiveParameter] string $accessToken, HttpTransport $transport, Sleeper $sleeper): self
    {
        if (preg_match(self::STORE_HASH_PATTERN, $storeHash) !== 1) {
            throw new SourceError('BigCommerce is not configured: enter the store hash (the code after /stores/ in the API path).');
        }
        $authorizer = new BigCommerceAuthorizer($accessToken);

        $rateLimit = new RateLimit(
            remainingHeader: self::REQUESTS_LEFT_HEADER,
            resetHeader: self::RESET_MS_HEADER,
            resetFormat: ResetFormat::MillisecondsFromNow,
        );

        return new self(new RestClient('BigCommerce', rtrim($apiBase, '/') . '/stores/' . $storeHash, $authorizer, $transport, $sleeper, $rateLimit), $storeHash);
    }

    public function shop(): Node
    {
        $store = $this->store();

        return self::identified('Store', $store->string('id'), $store->raw());
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        return match ($kind) {
            Kind::Collection => $this->categories($cursor),
            Kind::Product => $this->products($cursor, $since),
            Kind::Customer => $this->customers($cursor, $since),
            Kind::Discount => $this->discounts($cursor),
            Kind::Order => $this->orders($cursor, $since),
            Kind::Page => $this->pages($cursor),
            Kind::Blog => $this->blog(),
            Kind::Article => $this->posts($cursor),
            Kind::UrlRedirect => $this->redirects($cursor),
            Kind::Review => $this->reviews($cursor),
            Kind::GiftCard => $this->giftCertificates($cursor),
            default => throw new SourceError("BigCommerce has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        if (!in_array($kind, self::SINGLY_READABLE, true)) {
            throw new SourceError("BigCommerce can't read a single {$kind->value}.");
        }
        $type = self::type($gid);
        $id = self::numericId($gid);

        return match ($kind) {
            Kind::Collection => $this->first('/v3/catalog/trees/categories', ['category_id:in' => $id], 'Category', 'category_id'),
            Kind::Product => $this->product($id),
            Kind::Customer => $this->first('/v3/customers', ['id:in' => $id, 'include' => self::CUSTOMER_INCLUDES], 'Customer', 'id'),
            Kind::Discount => $type === 'Promotion' ? $this->promotion($id) : $this->coupon($id),
            Kind::Order => $this->order($id),
            Kind::Page => $this->contentPage($id),
            Kind::Article => $this->post($id),
            Kind::GiftCard => $this->giftCertificate($id),
            default => $this->first('/v3/storefront/redirects', ['id:in' => $id, 'include' => 'to_url'], 'Redirect', 'id'),
        };
    }

    public function isIncremental(Kind $kind): bool
    {
        return in_array($kind, self::INCREMENTAL, true);
    }

    private function categories(?string $cursor): Page
    {
        [$categories, $next] = $this->v3Page('/v3/catalog/trees/categories', [], $cursor, self::PAGE_SIZE);

        return self::paged(array_map(static fn(Node $category): Node => self::identified('Category', $category->int('category_id'), $category->raw()), $categories), $next);
    }

    private function products(?string $cursor, ?DateTimeImmutable $since): Page
    {
        $query = ['include' => self::PRODUCT_INCLUDES, 'sort' => 'id', 'direction' => 'asc'];
        if ($since !== null) {
            $query['date_modified:min'] = self::time($since);
        }
        [$products, $next] = $this->v3Page('/v3/catalog/products', $query, $cursor, self::PRODUCT_PAGE_SIZE);

        return self::paged($this->assembleProducts($products), $next);
    }

    private function product(string $id): ?Node
    {
        $product = $this->client->get("/v3/catalog/products/{$id}", ['include' => self::PRODUCT_INCLUDES])?->child('data');

        return $product === null ? null : $this->assembleProducts([$product])[0];
    }

    /**
     * Products with their variants (every page of them), brand name and the store's weight unit.
     *
     * @param list<Node> $products
     *
     * @return list<Node>
     */
    private function assembleProducts(array $products): array
    {
        $brands = $this->brandNames($products);
        $weightUnits = $this->store()->optionalString('weight_units');

        return array_map(function (Node $product) use ($brands, $weightUnits): Node {
            $id = $product->int('id');
            $variants = $this->allV3("/v3/catalog/products/{$id}/variants", []);

            return self::identified('Product', $id, [
                ...$product->raw(),
                'variants' => array_map(static fn(Node $variant): array => $variant->raw(), $variants),
                'brand' => $brands[$product->optionalInt('brand_id') ?? 0] ?? null,
                'weight_units' => $weightUnits,
            ]);
        }, $products);
    }

    /**
     * @param list<Node> $products
     *
     * @return array<int, string> brand id → name, for the brands these products name
     */
    private function brandNames(array $products): array
    {
        $ids = self::unique(array_map(static fn(Node $product): int => $product->optionalInt('brand_id') ?? 0, $products));
        $names = [];
        foreach ($ids === [] ? [] : $this->allV3('/v3/catalog/brands', ['id:in' => implode(',', $ids)]) as $brand) {
            $names[$brand->int('id')] = $brand->string('name');
        }

        return $names;
    }

    private function customers(?string $cursor, ?DateTimeImmutable $since): Page
    {
        $query = ['include' => self::CUSTOMER_INCLUDES, 'sort' => 'date_created:asc'];
        if ($since !== null) {
            $query['date_modified:min'] = self::time($since);
        }
        [$customers, $next] = $this->v3Page('/v3/customers', $query, $cursor, self::PAGE_SIZE);

        return self::paged(array_map(static fn(Node $customer): Node => self::identified('Customer', $customer->int('id'), $customer->raw()), $customers), $next);
    }

    /** Legacy coupons, then promotions; the cursor is "<phase>:<page>". */
    private function discounts(?string $cursor): Page
    {
        [$phase, $page] = $cursor === null ? [self::PHASE_COUPONS, ''] : array_pad(explode(self::CURSOR_SEPARATOR, $cursor, 2), 2, '');
        $page = $page === '' ? null : $page;
        if ($phase === self::PHASE_COUPONS) {
            [$coupons, $next] = $this->v2Page('/v2/coupons', ['exclude_type' => 'promotion'], $page);
            $nodes = array_map(static fn(Node $coupon): Node => self::identified('Coupon', $coupon->int('id'), $coupon->raw()), $coupons);

            return new Page($nodes, true, $next === null ? self::PHASE_PROMOTIONS . self::CURSOR_SEPARATOR : self::PHASE_COUPONS . self::CURSOR_SEPARATOR . $next);
        }
        [$promotions, $next] = $this->v3Page('/v3/promotions', [], $page, self::PAGE_SIZE);
        $nodes = array_map($this->withCodes(...), $promotions);

        return new Page($nodes, $next !== null, $next === null ? null : self::PHASE_PROMOTIONS . self::CURSOR_SEPARATOR . $next);
    }

    private function coupon(string $id): ?Node
    {
        $coupon = $this->client->get('/v2/coupons', ['id' => $id])?->list(RestClient::LIST_KEY)[0] ?? null;

        return $coupon === null ? null : self::identified('Coupon', $coupon->int('id'), $coupon->raw());
    }

    private function promotion(string $id): ?Node
    {
        $promotion = $this->client->get("/v3/promotions/{$id}")?->child('data');

        return $promotion === null ? null : $this->withCodes($promotion);
    }

    /** A promotion, with every one of its codes when customers redeem it with a code. */
    private function withCodes(Node $promotion): Node
    {
        $id = $promotion->int('id');
        $codes = [];
        if ($promotion->string('redemption_type') === self::COUPON_PROMOTION) {
            $after = null;
            do {
                $response = $this->required("/v3/promotions/{$id}/codes", ['limit' => self::PAGE_SIZE, ...($after === null ? [] : ['after' => $after])]);
                $codes = [...$codes, ...array_map(static fn(Node $code): array => $code->raw(), $response->list('data'))];
                $paging = $response->child('meta')?->child('cursor_pagination');
                $after = $paging === null || $paging->child('links')?->optionalString('next') === null ? null : $paging->optionalString('end_cursor');
            } while ($after !== null);
        }

        return self::identified('Promotion', $id, [...$promotion->raw(), 'codes' => $codes]);
    }

    private function orders(?string $cursor, ?DateTimeImmutable $since): Page
    {
        $query = ['include' => 'fees', 'sort' => 'id:asc'];
        if ($since !== null) {
            $query['min_date_modified'] = self::time($since);
        }
        [$orders, $next] = $this->v2Page('/v2/orders', $query, $cursor);

        return self::paged($this->assembleOrders($orders), $next);
    }

    private function order(string $id): ?Node
    {
        $order = $this->client->get("/v2/orders/{$id}", ['include' => 'fees']);

        return $order === null ? null : $this->assembleOrders([$order])[0];
    }

    /**
     * Orders with their v2 sub-resources (every page of each) and their refunds (read for the whole page).
     *
     * @param list<Node> $orders
     *
     * @return list<Node>
     */
    private function assembleOrders(array $orders): array
    {
        $ids = array_map(static fn(Node $order): int => $order->int('id'), $orders);
        $refunds = [];
        foreach ($ids === [] ? [] : $this->allV3('/v3/orders/payment_actions/refunds', ['order_id:in' => implode(',', $ids)]) as $refund) {
            $refunds[$refund->int('order_id')][] = $refund->raw();
        }

        return array_map(function (Node $order) use ($refunds): Node {
            $id = $order->int('id');
            $parts = [];
            foreach (self::ORDER_PARTS as $part) {
                $query = $part === 'taxes' ? ['details' => 'true'] : [];
                $parts[$part] = array_map(static fn(Node $item): array => $item->raw(), $this->allV2("/v2/orders/{$id}/{$part}", $query));
            }

            return self::identified('Order', $id, [...$order->raw(), ...$parts, 'refunds' => $refunds[$id] ?? []]);
        }, $orders);
    }

    /** Content pages other than the blog's own page (that is the Blog). */
    private function pages(?string $cursor): Page
    {
        [$pages, $next] = $this->v3Page('/v3/content/pages', ['include' => 'body'], $cursor, self::PAGE_SIZE);
        $pages = array_values(array_filter($pages, static fn(Node $page): bool => $page->string('type') !== self::BLOG_PAGE_TYPE));

        return self::paged(array_map(static fn(Node $page): Node => self::identified('Page', $page->int('id'), $page->raw()), $pages), $next);
    }

    private function contentPage(string $id): ?Node
    {
        $page = $this->client->get("/v3/content/pages/{$id}", ['include' => 'body'])?->child('data');

        return $page === null || $page->string('type') === self::BLOG_PAGE_TYPE ? null : self::identified('Page', $page->int('id'), $page->raw());
    }

    /** The store's one blog, named by its blog page (BigCommerce keeps one per storefront; the first is used). */
    private function blog(): Page
    {
        $blogPage = null;
        foreach ($this->allV3('/v3/content/pages', []) as $page) {
            if ($page->string('type') === self::BLOG_PAGE_TYPE) {
                $blogPage = $page;
                break;
            }
        }

        return new Page([self::identified('Blog', $this->storeHash, ['page' => $blogPage?->raw()])], false, null);
    }

    private function posts(?string $cursor): Page
    {
        [$posts, $next] = $this->v2Page('/v2/blog/posts', [], $cursor);

        return self::paged(array_map($this->withStore(...), $posts), $next);
    }

    private function post(string $id): ?Node
    {
        $post = $this->client->get("/v2/blog/posts/{$id}");

        return $post === null ? null : $this->withStore($post);
    }

    /** A blog post with its blog's identity and the store's URL (thumbnails are paths on the store). */
    private function withStore(Node $post): Node
    {
        return self::identified('Post', $post->int('id'), [
            ...$post->raw(),
            'blog_id' => $this->storeHash,
            'store_url' => $this->store()->optionalString('secure_url'),
        ]);
    }

    /** Gift certificates (v2, page by page; BigCommerce has no modified-since filter for them). */
    private function giftCertificates(?string $cursor): Page
    {
        [$certificates, $next] = $this->v2Page('/v2/gift_certificates', [], $cursor);

        return self::paged(array_map(static fn(Node $certificate): Node => self::identified('GiftCertificate', $certificate->int('id'), $certificate->raw()), $certificates), $next);
    }

    private function giftCertificate(string $id): ?Node
    {
        $certificate = $this->client->get("/v2/gift_certificates/{$id}");

        return $certificate === null ? null : self::identified('GiftCertificate', $certificate->int('id'), $certificate->raw());
    }

    private function redirects(?string $cursor): Page
    {
        [$redirects, $next] = $this->v3Page('/v3/storefront/redirects', ['include' => 'to_url'], $cursor, self::PAGE_SIZE);

        return self::paged(array_map(static fn(Node $redirect): Node => self::identified('Redirect', $redirect->int('id'), $redirect->raw()), $redirects), $next);
    }

    /** One page of products (only their review counts), as the reviews of those that have any. */
    private function reviews(?string $cursor): Page
    {
        [$products, $next] = $this->v3Page('/v3/catalog/products', ['include_fields' => 'reviews_count', 'sort' => 'id', 'direction' => 'asc'], $cursor, self::PAGE_SIZE);
        $nodes = [];
        foreach ($products as $product) {
            if (($product->optionalInt('reviews_count') ?? 0) < 1) {
                continue;
            }
            $productId = $product->int('id');
            foreach ($this->allV3("/v3/catalog/products/{$productId}/reviews", []) as $review) {
                $nodes[] = self::identified('Review', $review->int('id'), [...$review->raw(), 'product_id' => $productId]);
            }
        }

        return self::paged($nodes, $next);
    }

    private function store(): Node
    {
        return $this->store ??= $this->required('/v2/store');
    }

    /**
     * One page of a v3 list: its items (`data`) and the next page number, if `meta.pagination` has more.
     *
     * @param array<string, scalar> $query
     *
     * @return array{0: list<Node>, 1: ?string}
     */
    private function v3Page(string $path, array $query, ?string $cursor, int $limit): array
    {
        $page = self::pageNumber($cursor);
        $response = $this->required($path, [...$query, 'page' => $page, 'limit' => $limit]);
        $totalPages = $response->child('meta')?->child('pagination')?->optionalInt('total_pages') ?? $page;

        return [$response->list('data'), $page < $totalPages ? (string) ($page + 1) : null];
    }

    /**
     * One page of a v2 list (a bare array, or 204 when empty) and the next page number, if this page was full.
     *
     * @param array<string, scalar> $query
     *
     * @return array{0: list<Node>, 1: ?string}
     */
    private function v2Page(string $path, array $query, ?string $cursor): array
    {
        $page = self::pageNumber($cursor);
        $items = $this->client->get($path, [...$query, 'page' => $page, 'limit' => self::PAGE_SIZE])?->list(RestClient::LIST_KEY) ?? [];

        return [$items, count($items) >= self::PAGE_SIZE ? (string) ($page + 1) : null];
    }

    /**
     * Every item of a v3 list. A list with no paging information (refunds) ends at a short page.
     *
     * @param array<string, scalar> $query
     *
     * @return list<Node>
     */
    private function allV3(string $path, array $query): array
    {
        $items = [];
        $page = self::FIRST_PAGE;
        while (true) {
            $response = $this->required($path, [...$query, 'page' => $page, 'limit' => self::PAGE_SIZE]);
            $data = $response->list('data');
            $items = [...$items, ...$data];
            $totalPages = $response->child('meta')?->child('pagination')?->optionalInt('total_pages');
            if ($totalPages === null ? count($data) < self::PAGE_SIZE : $page >= $totalPages) {
                return $items;
            }
            ++$page;
        }
    }

    /**
     * Every item of a v2 list.
     *
     * @param array<string, scalar> $query
     *
     * @return list<Node>
     */
    private function allV2(string $path, array $query): array
    {
        $items = [];
        $cursor = null;
        do {
            [$page, $cursor] = $this->v2Page($path, $query, $cursor);
            $items = [...$items, ...$page];
        } while ($cursor !== null);

        return $items;
    }

    /**
     * The first object of a filtered v3 list, identified, or null when there is none.
     *
     * @param array<string, scalar> $query
     */
    private function first(string $path, array $query, string $type, string $idField): ?Node
    {
        $object = $this->client->get($path, $query)?->list('data')[0] ?? null;

        return $object === null ? null : self::identified($type, $object->int($idField), $object->raw());
    }

    /** @param array<string, scalar> $query */
    private function required(string $path, array $query = []): Node
    {
        return $this->client->get($path, $query) ?? throw new SourceError("BigCommerce has no {$path} for this store.");
    }

    /** @param list<Node> $nodes */
    private static function paged(array $nodes, ?string $next): Page
    {
        return new Page($nodes, $next !== null, $next);
    }

    private static function pageNumber(?string $cursor): int
    {
        return $cursor === null || $cursor === '' ? self::FIRST_PAGE : max(self::FIRST_PAGE, (int) $cursor);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int> distinct, sorted, positive
     */
    private static function unique(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    /** The type part of an identity (`gid://bigcommerce/<Type>/<id>`). */
    private static function type(string $gid): string
    {
        $parts = explode('/', $gid);

        return $parts[count($parts) - 2] ?? '';
    }

    private static function numericId(string $gid): string
    {
        $id = substr($gid, strrpos($gid, '/') + 1);

        return preg_match('/^\d+$/', $id) === 1 ? $id : throw new SourceError("Not a BigCommerce identity: {$gid}");
    }

    private static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, int|string $id, array $raw): Node
    {
        $gid = Identity::of(BigCommerceAdapter::ID, $type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
