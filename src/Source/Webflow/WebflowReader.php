<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RateLimit;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\Webflow\Mapping\Fields;

/**
 * Reads a site from Webflow's Data API v2 (developers.webflow.com/data/reference): `https://api.webflow.com/v2`,
 * every list paged by `offset` and `limit` (at most 100) with a `pagination.total`. Every node carries its
 * identity as `id` (`gid://webflow/<Type>/<id>`).
 *
 * - The shop is the site with its e-commerce settings (none for a site without e-commerce).
 * - Assets are files; e-commerce categories are the items of the site's Categories collection (slug
 *   `category`); products arrive with their SKUs and each SKU's inventory (one request per SKU, from the SKUs
 *   collection, slug `sku`); orders are read as listed.
 * - Customers: Webflow keeps no customer object for buyers, so they are gathered from every order by email
 *   (the first order's name and addresses), in one pass over the orders.
 * - Pages are the static pages (a collection's template page is its items); each arrives with its DOM (paged)
 *   and the assets its images name.
 * - The collection the operator names as the blog is a blog, its items articles; every other collection
 *   (not Products, SKUs or Categories) is a content type, its items content items, with the collection's field
 *   definitions on each item.
 *
 * Nothing is incremental: Webflow's product, order and redirect lists have no changed-since filter, so every
 * run reads in full and unchanged objects are skipped by hash.
 */
final class WebflowReader implements SourceReader
{
    public const API_BASE = 'https://api.webflow.com/v2';
    public const PAGE_SIZE = 100;
    /**
     * Webflow allows 60 requests a minute per token on Starter and Basic sites and 120 on CMS, e-commerce and
     * Business sites, and reports what is left of the minute in `X-RateLimit-Remaining`; past it every request
     * is a 429 with a `Retry-After`, typically 60 seconds (developers.webflow.com/data/reference/rate-limits).
     * Requests are paced at the lower plan's rate, and once the minute is down to its last request the next
     * waits for the window.
     */
    public const REQUESTS_PER_MINUTE = 60;
    public const SECONDS_PER_MINUTE = 60;
    public const REMAINING_HEADER = 'X-RateLimit-Remaining';
    /** The slugs of the collections Webflow keeps for e-commerce (read through the e-commerce endpoints). */
    public const PRODUCTS_SLUG = 'product';
    public const SKUS_SLUG = 'sku';
    public const CATEGORIES_SLUG = 'category';

    private const FIRST_OFFSET = 0;
    /** Content items are read collection by collection: the cursor is `<collection index>:<offset>`. */
    private const CURSOR_SEPARATOR = ':';

    /** @var list<Node>|null */
    private ?array $collections = null;
    /** @var array<string, Node> collection id → its details (with fields) */
    private array $details = [];
    /** @var array<string, ?Node> asset id → the asset */
    private array $assets = [];

    public function __construct(
        private readonly RestClient $client,
        private readonly string $siteId,
        private readonly ?string $blogCollectionId,
    ) {}

    public static function connect(string $apiBase, string $siteId, ?string $blogCollectionId, RequestAuthorizer $authorizer, HttpTransport $transport, Sleeper $sleeper): self
    {
        if (preg_match('/^[0-9a-f]{24}$/', $siteId) !== 1) {
            throw new SourceError('Webflow is not configured: enter the site ID (24 hexadecimal characters, from the site\'s General settings).');
        }
        $rateLimit = new RateLimit(
            requestsPerSecond: RateLimit::perPeriod(self::REQUESTS_PER_MINUTE, self::SECONDS_PER_MINUTE),
            cooldownSeconds: self::SECONDS_PER_MINUTE,
            remainingHeader: self::REMAINING_HEADER,
            windowSeconds: self::SECONDS_PER_MINUTE,
        );

        return new self(new RestClient('Webflow', rtrim($apiBase, '/'), $authorizer, $transport, $sleeper, $rateLimit), $siteId, $blogCollectionId === '' ? null : $blogCollectionId);
    }

    public function shop(): Node
    {
        $site = $this->required("/sites/{$this->siteId}");
        $ecommerce = $this->client->get("/sites/{$this->siteId}/ecommerce/settings");

        return self::identified('Shop', $this->siteId, [...$site->raw(), 'ecommerce' => $ecommerce?->raw()]);
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        return match ($kind) {
            Kind::File => $this->list("/sites/{$this->siteId}/assets", 'assets', $cursor, fn(Node $asset): Node => self::identified('Asset', $asset->id(), $asset->raw())),
            Kind::Collection => $this->categories($cursor),
            Kind::Product => $this->list("/sites/{$this->siteId}/products", 'items', $cursor, $this->product(...)),
            Kind::Customer => new Page($this->customers(), false, null),
            Kind::Order => $this->list("/sites/{$this->siteId}/orders", 'orders', $cursor, static fn(Node $order): Node => self::identified('Order', $order->string('orderId'), $order->raw())),
            Kind::Page => $this->pages($cursor),
            Kind::Blog => new Page($this->blog() === null ? [] : [self::identified('Collection', $this->blog()->id(), $this->blog()->raw())], false, null),
            Kind::Article => $this->blog() === null ? new Page([], false, null) : $this->items($this->blog(), $cursor),
            Kind::ContentType => new Page(array_map(static fn(Node $collection): Node => self::identified('Collection', $collection->id(), $collection->raw()), $this->contentCollections()), false, null),
            Kind::ContentItem => $this->contentItems($cursor),
            Kind::UrlRedirect => $this->list("/sites/{$this->siteId}/redirects", 'redirects', $cursor, static fn(Node $redirect): Node => self::identified('Redirect', $redirect->id(), $redirect->raw())),
            default => throw new SourceError("Webflow has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        $id = rawurlencode(Identity::sourceId($gid));
        if ($kind === Kind::Product) {
            $product = $this->client->get("/sites/{$this->siteId}/products/{$id}");

            return $product === null ? null : $this->product($product);
        }
        if ($kind === Kind::Order) {
            $order = $this->client->get("/sites/{$this->siteId}/orders/{$id}");

            return $order === null ? null : self::identified('Order', $order->string('orderId'), $order->raw());
        }

        throw new SourceError("Webflow can't read a single {$kind->value}; only products and orders.");
    }

    public function isIncremental(Kind $kind): bool
    {
        return false;
    }

    /**
     * One page of a list.
     *
     * @param callable(Node): Node $assemble
     */
    private function list(string $path, string $field, ?string $cursor, callable $assemble): Page
    {
        $offset = self::offset($cursor);
        $response = $this->required($path, ['offset' => $offset, 'limit' => self::PAGE_SIZE]);
        $items = $response->list($field);
        $next = $offset + count($items);
        $more = $items !== [] && $next < ($response->child('pagination')?->optionalInt('total') ?? 0);

        return new Page(array_map($assemble, $items), $more, $more ? (string) $next : null);
    }

    /** A product (`{product, skus}`) with each SKU's inventory. */
    private function product(Node $item): Node
    {
        $product = $item->requiredChild('product');
        $skuCollection = $this->collectionBySlug(self::SKUS_SLUG);
        $skus = [];
        foreach ($item->list('skus') as $sku) {
            $inventory = $skuCollection === null ? null : $this->client->get('/collections/' . $skuCollection->id() . '/items/' . rawurlencode($sku->id()) . '/inventory');
            $skus[] = [...$sku->raw(), 'id' => Fields::variantId($sku->id()), 'inventory' => $inventory?->raw()];
        }

        return self::identified('Product', $product->id(), [...$product->raw(), 'skus' => $skus]);
    }

    /** The items of the Categories collection, as categories. */
    private function categories(?string $cursor): Page
    {
        $collection = $this->collectionBySlug(self::CATEGORIES_SLUG);

        return $collection === null ? new Page([], false, null) : $this->items($this->details($collection), $cursor, 'Category');
    }

    /** One page of a collection's items, each with its collection's id, slug and field definitions. */
    private function items(Node $collection, ?string $cursor, string $type = 'Item'): Page
    {
        $context = ['collection_id' => $collection->id(), 'collection_slug' => $collection->string('slug'), 'collection_fields' => $collection->raw()['fields'] ?? []];

        return $this->list('/collections/' . $collection->id() . '/items', 'items', $cursor, static fn(Node $item): Node => self::identified($type, $item->id(), [...$item->raw(), ...$context]));
    }

    /** Every content collection's items, collection by collection. */
    private function contentItems(?string $cursor): Page
    {
        [$index, $offset] = array_pad(explode(self::CURSOR_SEPARATOR, $cursor ?? ''), 2, '');
        $collections = $this->contentCollections();
        $index = (int) $index;
        $collection = $collections[$index] ?? null;
        if ($collection === null) {
            return new Page([], false, null);
        }
        $page = $this->items($collection, $offset === '' ? null : $offset);
        if ($page->hasNextPage) {
            return new Page($page->nodes, true, $index . self::CURSOR_SEPARATOR . $page->endCursor);
        }
        $more = isset($collections[$index + 1]);

        return new Page($page->nodes, $more, $more ? ($index + 1) . self::CURSOR_SEPARATOR : null);
    }

    /** One page of static pages, each with its DOM and the assets its images name. */
    private function pages(?string $cursor): Page
    {
        $page = $this->list("/sites/{$this->siteId}/pages", 'pages', $cursor, static fn(Node $page): Node => $page);
        $nodes = [];
        foreach ($page->nodes as $webPage) {
            if ($webPage->optionalString('collectionId') !== null) {
                continue;
            }
            $dom = $this->dom($webPage->id());
            $assets = [];
            foreach ($dom as $element) {
                $assetId = $element['type'] === 'image' && is_array($element['image'] ?? null) && is_string($element['image']['assetId'] ?? null) ? $element['image']['assetId'] : null;
                $asset = $assetId === null ? null : $this->asset($assetId);
                if ($assetId !== null && $asset !== null) {
                    $assets[$assetId] = ['url' => $asset->string('hostedUrl'), 'alt' => $asset->optionalString('altText')];
                }
            }
            $nodes[] = self::identified('Page', $webPage->id(), [...$webPage->raw(), 'dom_nodes' => $dom, 'assets' => $assets]);
        }

        return new Page($nodes, $page->hasNextPage, $page->endCursor);
    }

    /** @return list<array<array-key, mixed>> every node of a page's DOM, in order */
    private function dom(string $pageId): array
    {
        $nodes = [];
        $offset = self::FIRST_OFFSET;
        do {
            $response = $this->required('/pages/' . rawurlencode($pageId) . '/dom', ['offset' => $offset, 'limit' => self::PAGE_SIZE]);
            $batch = $response->list('nodes');
            foreach ($batch as $element) {
                $nodes[] = $element->raw();
            }
            $offset += count($batch);
        } while ($batch !== [] && $offset < ($response->child('pagination')?->optionalInt('total') ?? 0));

        return $nodes;
    }

    private function asset(string $id): ?Node
    {
        if (!array_key_exists($id, $this->assets)) {
            $this->assets[$id] = $this->client->get('/assets/' . rawurlencode($id));
        }

        return $this->assets[$id];
    }

    /**
     * Every buyer, gathered from all orders by email: the first order's name and addresses, and when it was
     * placed. Orders are read in full here, so a customer is the same whichever page their orders are on.
     *
     * @return list<Node>
     */
    private function customers(): array
    {
        $first = [];
        $cursor = null;
        do {
            $page = $this->list("/sites/{$this->siteId}/orders", 'orders', $cursor, static fn(Node $order): Node => $order);
            foreach ($page->nodes as $order) {
                $email = $order->child('customerInfo')?->optionalString('email');
                if ($email === null) {
                    continue;
                }
                $key = strtolower(trim($email));
                $placed = $order->optionalString('acceptedOn') ?? '';
                if (!isset($first[$key]) || strcmp($placed, $first[$key]->optionalString('acceptedOn') ?? '') < 0) {
                    $first[$key] = $order;
                }
            }
            $cursor = $page->endCursor;
        } while ($page->hasNextPage);
        ksort($first, SORT_STRING);
        $customers = [];
        foreach ($first as $email => $order) {
            $gid = Fields::customerId($email);
            $customers[] = new Node([
                'id' => $gid,
                'email' => $email,
                'fullName' => $order->child('customerInfo')?->optionalString('fullName'),
                'billingAddress' => $order->child('billingAddress')?->raw(),
                'shippingAddress' => $order->child('shippingAddress')?->raw(),
                'firstOrderOn' => $order->optionalString('acceptedOn'),
            ], $gid);
        }

        return $customers;
    }

    /** @return list<Node> the site's collections as listed */
    private function collections(): array
    {
        return $this->collections ??= $this->required("/sites/{$this->siteId}/collections")->list('collections');
    }

    private function collectionBySlug(string $slug): ?Node
    {
        foreach ($this->collections() as $collection) {
            if ($collection->optionalString('slug') === $slug) {
                return $collection;
            }
        }

        return null;
    }

    /** A collection with its field definitions. */
    private function details(Node $collection): Node
    {
        return $this->details[$collection->id()] ??= $this->required('/collections/' . rawurlencode($collection->id()));
    }

    /** The blog collection, with its fields, when the operator named one. */
    private function blog(): ?Node
    {
        foreach ($this->collections() as $collection) {
            if ($collection->id() === $this->blogCollectionId) {
                return $this->details($collection);
            }
        }

        return null;
    }

    /** @return list<Node> every collection that is neither an e-commerce collection nor the blog, with its fields */
    private function contentCollections(): array
    {
        $content = [];
        foreach ($this->collections() as $collection) {
            if (!in_array($collection->optionalString('slug'), [self::PRODUCTS_SLUG, self::SKUS_SLUG, self::CATEGORIES_SLUG], true) && $collection->id() !== $this->blogCollectionId) {
                $content[] = $this->details($collection);
            }
        }

        return $content;
    }

    /** @param array<string, scalar> $query */
    private function required(string $path, array $query = []): Node
    {
        return $this->client->get($path, $query) ?? throw new SourceError("Webflow has no {$path} for this site.");
    }

    private static function offset(?string $cursor): int
    {
        return $cursor === null || $cursor === '' ? self::FIRST_OFFSET : max(self::FIRST_OFFSET, (int) $cursor);
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, string $id, array $raw): Node
    {
        $gid = Fields::id($type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
