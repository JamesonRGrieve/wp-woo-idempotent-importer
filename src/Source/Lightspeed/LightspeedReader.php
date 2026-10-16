<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RateLimit;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Lightspeed\Mapping\Fields;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;

/**
 * Reads a shop from Lightspeed eCom's REST API (developers.lightspeedhq.com/ecom/): `<cluster>/<language>/
 * <resource>.json`, every response wrapped in the resource's name (`{"products": […]}`, `{"product": {…}}`),
 * lists paged by page number (`page`, `limit` up to 250) and ending at a short page. Every node carries its
 * identity as `id` (`gid://lightspeed/<Type>/<id>`). Products arrive with their variants, images, category
 * ids and brand title; orders with their lines, shipments and credit notes (each with its items) and the
 * shop's currency; articles with their blog's URL; redirects with the shop's domain; products and orders with
 * whether the shop is B2B (it then shows prices without tax). Products, customers and orders are incremental
 * (`updated_at_min`): a product also counts as changed when one of its variants changed, and an order when
 * one of its shipments or invoices did, so those kinds read the changed children after the changed objects
 * (the cursor is "<phase>:<page>") and re-read their parents. The other kinds are read in full.
 */
final class LightspeedReader implements SourceReader
{
    /** API base per cluster (developers.lightspeedhq.com/ecom/introduction/clusters/). */
    public const CLUSTERS = ['eu1' => 'https://api.webshopapp.com', 'us1' => 'https://api.shoplightspeed.com'];
    /** The shop languages an API path names (ibid.); a shop's content is read in one of them. */
    public const LANGUAGES = ['bg', 'fc', 'da', 'nl', 'en', 'us', 'fr', 'de', 'el', 'it', 'no', 'pl', 'pt', 'ru', 'es', 'sv', 'tr'];
    /** Lists take up to 250 per page. */
    public const PAGE_SIZE = 250;
    /** Each product or order is followed by reads of its parts, so their pages are kept small. */
    public const PARENT_PAGE_SIZE = 50;
    /** `updated_at_min` takes a time with no zone (YYYY-MM-DD HH:MM:SS). */
    public const SINCE_FORMAT = 'Y-m-d H:i:s';
    /**
     * Lightspeed doesn't say which zone that time is in, so runs ask from a day earlier: whatever zone the
     * shop's clock is in, nothing changed since the last run is missed (re-reading an unchanged object writes
     * nothing).
     */
    public const SINCE_MARGIN = 'P1D';
    public const SINCE_FILTER = 'updated_at_min';
    /**
     * Lightspeed limits each shop's API key to 300 requests per 5 minutes, 3,000 per hour and 12,000 per day
     * (and 20 per second) (developers.lightspeedhq.com/ecom/introduction/rate-limiting/). Requests are paced
     * under the hourly bucket (one every 1.2 seconds, which also keeps under 300 per 5 minutes), so a small
     * shop imports quickly. Only a store needing more than 12,000 requests runs out the daily bucket; its 429
     * pauses the run, which resumes as the day's quota frees up (PausePolicy holds on for about a day), so
     * the total time is what the daily quota allows either way. The docs name no rate-limit response headers
     * and no cool-down after a 429, so pacing is by rate alone.
     */
    public const REQUESTS_PER_HOUR = 3_000;
    public const SECONDS_PER_HOUR = 3_600;

    private const PHASE_PRODUCTS = 'products';
    private const PHASE_VARIANTS = 'variants';
    private const PHASE_ORDERS = 'orders';
    private const PHASE_SHIPMENTS = 'shipments';
    private const PHASE_INVOICES = 'invoices';
    private const CURSOR_SEPARATOR = ':';
    private const FIRST_PAGE = 1;
    private const INCREMENTAL = [Kind::Product, Kind::Customer, Kind::Order];
    /** Kinds read as Lightspeed sends them: kind → [resource path, single envelope, list envelope, identity type]. */
    private const PLAIN = [
        'collection' => ['/categories', 'category', 'categories', 'Category'],
        'customer' => ['/customers', 'customer', 'customers', 'Customer'],
        'discount' => ['/discounts', 'discount', 'discounts', 'Discount'],
        'page' => ['/textpages', 'textpage', 'textpages', 'Textpage'],
        'blog' => ['/blogs', 'blog', 'blogs', 'Blog'],
        'review' => ['/reviews', 'review', 'reviews', 'Review'],
    ];

    private ?Node $shop = null;
    /** @var array<int, ?string> brand id → title */
    private array $brands = [];
    /** @var array<int, string>|null blog id → URL */
    private ?array $blogUrls = null;

    public function __construct(private readonly RestClient $client) {}

    public static function connect(string $apiBase, string $language, #[\SensitiveParameter] string $apiKey, #[\SensitiveParameter] string $apiSecret, HttpTransport $transport, Sleeper $sleeper): self
    {
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new SourceError('Lightspeed eCom is not configured: enter the shop language code (one of ' . implode(', ', self::LANGUAGES) . ').');
        }
        $authorizer = new LightspeedAuthorizer($apiKey, $apiSecret);

        $rateLimit = new RateLimit(requestsPerSecond: RateLimit::perPeriod(self::REQUESTS_PER_HOUR, self::SECONDS_PER_HOUR));

        return new self(new RestClient('Lightspeed eCom', rtrim($apiBase, '/') . '/' . $language, $authorizer, $transport, $sleeper, $rateLimit));
    }

    public function shop(): Node
    {
        $shop = $this->store();
        $website = $this->required('/shop/website.json')->requiredChild('shopWebsite');

        return self::identified('Shop', $shop->int('id'), [...$shop->raw(), 'website' => $website->raw()]);
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        return match ($kind) {
            Kind::Product => $this->products($cursor, $since),
            Kind::Order => $this->orders($cursor, $since),
            Kind::Article => $this->articles($cursor),
            Kind::UrlRedirect => $this->redirects($cursor),
            default => $this->plain($kind, $cursor, $since),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        $id = self::numericId($gid);

        return match ($kind) {
            Kind::Product => $this->product($id),
            Kind::Order => $this->order($id),
            Kind::Article => $this->article($id),
            Kind::UrlRedirect => $this->redirect($id),
            default => $this->plainNode($kind, $id),
        };
    }

    public function isIncremental(Kind $kind): bool
    {
        return in_array($kind, self::INCREMENTAL, true);
    }

    private function plain(Kind $kind, ?string $cursor, ?DateTimeImmutable $since): Page
    {
        [$path, , $listKey, $type] = self::PLAIN[$kind->value] ?? throw new SourceError("Lightspeed eCom has no {$kind->value}.");
        $query = $this->isIncremental($kind) ? self::changedSince($since) : [];
        [$items, $next] = $this->listPage("{$path}.json", $listKey, $query, self::pageNumber($cursor), self::PAGE_SIZE);

        return self::paged(array_map(static fn(Node $item): Node => self::identified($type, $item->int('id'), $item->raw()), $items), $next);
    }

    private function plainNode(Kind $kind, int $id): ?Node
    {
        [$path, $key, , $type] = self::PLAIN[$kind->value] ?? throw new SourceError("Lightspeed eCom has no {$kind->value}.");
        $item = $this->one("{$path}/{$id}.json", $key);

        return $item === null ? null : self::identified($type, $item->int('id'), $item->raw());
    }

    /** Products (changed since $since), then, on an incremental run, the products of changed variants. */
    private function products(?string $cursor, ?DateTimeImmutable $since): Page
    {
        [$phase, $page] = self::phase($cursor, self::PHASE_PRODUCTS);
        if ($phase === self::PHASE_PRODUCTS) {
            [$products, $next] = $this->listPage('/products.json', 'products', self::changedSince($since), $page, self::PARENT_PAGE_SIZE);

            return $this->phased($this->assembleProducts($products), self::PHASE_PRODUCTS, $next, $since === null ? null : self::PHASE_VARIANTS);
        }

        return $this->changedParents('/variants.json', 'variants', 'product', $since, self::PHASE_VARIANTS, $page, null, $this->product(...));
    }

    private function product(int $id): ?Node
    {
        $product = $this->one("/products/{$id}.json", 'product');

        return $product === null ? null : $this->assembleProducts([$product])[0];
    }

    /**
     * Products with every variant, their images, the ids of their categories and their brand's title.
     *
     * @param list<Node> $products
     *
     * @return list<Node>
     */
    private function assembleProducts(array $products): array
    {
        $isB2b = $this->isB2b();

        return array_map(function (Node $product) use ($isB2b): Node {
            $id = $product->int('id');
            $categories = [];
            foreach ($this->required('/categories/products.json', ['product' => $id])->list('categoriesProducts') as $link) {
                $categories[] = Fields::linkedId($link, 'category') ?? throw new SourceError("A category link of Lightspeed product {$id} names no category.");
            }

            return self::identified('Product', $id, [
                ...$product->raw(),
                'variants' => self::raws($this->all('/variants.json', 'variants', ['product' => $id])),
                'images' => self::raws($this->required("/products/{$id}/images.json")->list('productImages')),
                'category_ids' => $categories,
                'brand' => $this->brandTitle(Fields::linkedId($product, 'brand')),
                'shop_is_b2b' => $isB2b,
            ]);
        }, $products);
    }

    private function brandTitle(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }
        if (!array_key_exists($id, $this->brands)) {
            $this->brands[$id] = $this->one("/brands/{$id}.json", 'brand')?->optionalString('title');
        }

        return $this->brands[$id];
    }

    /** Orders (changed since $since), then, on an incremental run, the orders of changed shipments and invoices. */
    private function orders(?string $cursor, ?DateTimeImmutable $since): Page
    {
        [$phase, $page] = self::phase($cursor, self::PHASE_ORDERS);
        if ($phase === self::PHASE_ORDERS) {
            [$orders, $next] = $this->listPage('/orders.json', 'orders', self::changedSince($since), $page, self::PARENT_PAGE_SIZE);

            return $this->phased($this->assembleOrders($orders), self::PHASE_ORDERS, $next, $since === null ? null : self::PHASE_SHIPMENTS);
        }

        return $phase === self::PHASE_SHIPMENTS
            ? $this->changedParents('/shipments.json', 'shipments', 'order', $since, self::PHASE_SHIPMENTS, $page, self::PHASE_INVOICES, $this->order(...))
            : $this->changedParents('/invoices.json', 'invoices', 'order', $since, self::PHASE_INVOICES, $page, null, $this->order(...));
    }

    private function order(int $id): ?Node
    {
        $order = $this->one("/orders/{$id}.json", 'order');

        return $order === null ? null : $this->assembleOrders([$order])[0];
    }

    /**
     * Orders with every line, their shipments, their credit notes (each with its items), the shop's currency
     * (orders don't name one) and whether the shop is B2B.
     *
     * @param list<Node> $orders
     *
     * @return list<Node>
     */
    private function assembleOrders(array $orders): array
    {
        $currency = $this->store()->requiredChild('currency')->string('shortcode');
        $isB2b = $this->isB2b();

        return array_map(function (Node $order) use ($currency, $isB2b): Node {
            $id = $order->int('id');
            $creditNotes = [];
            foreach ($this->all('/invoices.json', 'invoices', ['order' => $id]) as $invoice) {
                if (($invoice->raw()['isCreditNote'] ?? false) === true) {
                    $items = $this->required("/invoices/{$invoice->int('id')}/items.json")->list('invoiceItems');
                    $creditNotes[] = [...$invoice->raw(), 'items' => self::raws($items)];
                }
            }

            return self::identified('Order', $id, [
                ...$order->raw(),
                'products' => self::raws($this->all("/orders/{$id}/products.json", 'orderProducts', [])),
                'shipments' => self::raws($this->all('/shipments.json', 'shipments', ['order' => $id])),
                'credit_notes' => $creditNotes,
                'currency' => $currency,
                'shop_is_b2b' => $isB2b,
            ]);
        }, $orders);
    }

    private function articles(?string $cursor): Page
    {
        [$articles, $next] = $this->listPage('/blogs/articles.json', 'blogArticles', [], self::pageNumber($cursor), self::PAGE_SIZE);

        return self::paged(array_map($this->withBlog(...), $articles), $next);
    }

    private function article(int $id): ?Node
    {
        $article = $this->one("/blogs/articles/{$id}.json", 'blogArticle');

        return $article === null ? null : $this->withBlog($article);
    }

    /** An article with its blog's URL (its storefront path is under the blog's). */
    private function withBlog(Node $article): Node
    {
        $blogId = Fields::linkedId($article, 'blog');

        return self::identified('Article', $article->int('id'), [
            ...$article->raw(),
            'blog_url' => $blogId === null ? null : $this->blogUrls()[$blogId] ?? null,
        ]);
    }

    /** @return array<int, string> */
    private function blogUrls(): array
    {
        if ($this->blogUrls === null) {
            $this->blogUrls = [];
            foreach ($this->all('/blogs.json', 'blogs', []) as $blog) {
                $this->blogUrls[$blog->int('id')] = $blog->string('url');
            }
        }

        return $this->blogUrls;
    }

    private function redirects(?string $cursor): Page
    {
        [$redirects, $next] = $this->listPage('/redirects.json', 'redirects', [], self::pageNumber($cursor), self::PAGE_SIZE);

        return self::paged(array_map($this->withDomain(...), $redirects), $next);
    }

    private function redirect(int $id): ?Node
    {
        $redirect = $this->one("/redirects/{$id}.json", 'redirect');

        return $redirect === null ? null : $this->withDomain($redirect);
    }

    /** A redirect with the shop's domain (a target on it is an old storefront URL). */
    private function withDomain(Node $redirect): Node
    {
        return self::identified('Redirect', $redirect->int('id'), [...$redirect->raw(), 'shop_domain' => $this->store()->optionalString('mainDomain')]);
    }

    /**
     * One page of the children changed since $since (variants, shipments, invoices), as their parents read
     * afresh (each once per page), then the next phase.
     *
     * @param callable(int): ?Node $read
     */
    private function changedParents(string $path, string $key, string $parentField, ?DateTimeImmutable $since, string $phase, int $page, ?string $nextPhase, callable $read): Page
    {
        [$children, $next] = $this->listPage($path, $key, self::changedSince($since), $page, self::PAGE_SIZE);
        $ids = [];
        foreach ($children as $child) {
            $id = Fields::linkedId($child, $parentField);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }
        $nodes = array_values(array_filter(array_map($read, array_values($ids)), static fn(?Node $node): bool => $node !== null));

        return $this->phased($nodes, $phase, $next, $nextPhase);
    }

    /** @param list<Node> $nodes */
    private function phased(array $nodes, string $phase, ?string $next, ?string $nextPhase): Page
    {
        if ($next !== null) {
            return new Page($nodes, true, $phase . self::CURSOR_SEPARATOR . $next);
        }

        return new Page($nodes, $nextPhase !== null, $nextPhase === null ? null : $nextPhase . self::CURSOR_SEPARATOR);
    }

    private function store(): Node
    {
        return $this->shop ??= $this->required('/shop.json')->requiredChild('shop');
    }

    private function isB2b(): bool
    {
        return ($this->store()->raw()['isB2b'] ?? false) === true;
    }

    /**
     * One page of a list (its envelope's items) and the next page number, if this page was full.
     *
     * @param array<string, scalar> $query
     *
     * @return array{0: list<Node>, 1: ?string}
     */
    private function listPage(string $path, string $key, array $query, int $page, int $limit): array
    {
        $items = $this->required($path, [...$query, 'page' => $page, 'limit' => $limit])->list($key);

        return [$items, count($items) >= $limit ? (string) ($page + 1) : null];
    }

    /**
     * Every item of a list.
     *
     * @param array<string, scalar> $query
     *
     * @return list<Node>
     */
    private function all(string $path, string $key, array $query): array
    {
        $items = [];
        $page = self::FIRST_PAGE;
        do {
            [$batch, $next] = $this->listPage($path, $key, $query, $page, self::PAGE_SIZE);
            $items = [...$items, ...$batch];
            ++$page;
        } while ($next !== null);

        return $items;
    }

    /** One object by its path, unwrapped from its envelope; null when Lightspeed has no such object. */
    private function one(string $path, string $key): ?Node
    {
        return $this->client->get($path)?->requiredChild($key);
    }

    /** @param array<string, scalar> $query */
    private function required(string $path, array $query = []): Node
    {
        return $this->client->get($path, $query) ?? throw new SourceError("Lightspeed eCom has no {$path} for this shop.");
    }

    /** @return array<string, string> the change filter, from SINCE_MARGIN before $since */
    private static function changedSince(?DateTimeImmutable $since): array
    {
        if ($since === null) {
            return [];
        }
        $from = $since->sub(new DateInterval(self::SINCE_MARGIN))->setTimezone(new DateTimeZone('UTC'));

        return [self::SINCE_FILTER => $from->format(self::SINCE_FORMAT)];
    }

    /** @return array{0: string, 1: int} a "<phase>:<page>" cursor's phase and page */
    private static function phase(?string $cursor, string $first): array
    {
        if ($cursor === null || $cursor === '') {
            return [$first, self::FIRST_PAGE];
        }
        [$phase, $page] = array_pad(explode(self::CURSOR_SEPARATOR, $cursor, 2), 2, '');

        return [$phase, self::pageNumber($page)];
    }

    private static function pageNumber(?string $cursor): int
    {
        return $cursor === null || $cursor === '' ? self::FIRST_PAGE : max(self::FIRST_PAGE, (int) $cursor);
    }

    /** @param list<Node> $nodes */
    private static function paged(array $nodes, ?string $next): Page
    {
        return new Page($nodes, $next !== null, $next);
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<array<array-key, mixed>>
     */
    private static function raws(array $nodes): array
    {
        return array_map(static fn(Node $node): array => $node->raw(), $nodes);
    }

    private static function numericId(string $gid): int
    {
        $id = substr($gid, strrpos($gid, '/') + 1);

        return preg_match('/^\d+$/', $id) === 1 ? (int) $id : throw new SourceError("Not a Lightspeed eCom identity: {$gid}");
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, int $id, array $raw): Node
    {
        $gid = Identity::of(LightspeedAdapter::ID, $type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
