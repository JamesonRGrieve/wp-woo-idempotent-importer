<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Magento;

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
 * Reads one store view of a Magento 2.4 store through its REST API (`<store URL>/rest/<store code>/V1/…`, so
 * names and descriptions are the store view's). Lists are searches paged by searchCriteria (page number and
 * size); Magento answers a page past the end with the last page again, so a list ends when the items read
 * reach its total_count, never at an empty page. Every node carries its identity as `id`
 * (`gid://magento/<Type>/<id>`). Products are the catalog-visible ones (a product "not visible individually"
 * is a configurable product's child) and arrive with the store's media URL, website, root categories and
 * weight unit, and a configurable product with its child products and the attributes it is configured by
 * (injected as `store`, `children` and `attributes`); cart price rules with their coupons, the store's
 * customer groups, website and timezone; orders (of the store's website's store views) with their credit
 * memos and shipments. Customers and orders are incremental (by updated_at). Products aren't: Magento doesn't
 * move a product's updated_at when its stock changes or when a child of a configurable product changes, so
 * products are read in full; categories, rules and pages have no such filter either.
 */
final class MagentoReader implements SourceReader
{
    public const DEFAULT_STORE_CODE = 'default';
    /** Magento's default "Maximum Page Size" for REST searches is 300 (Web API Input Limits); stay well under it. */
    public const PAGE_SIZE = 100;
    public const NOT_VISIBLE_INDIVIDUALLY = 1;
    public const CONFIGURABLE = 'configurable';
    /** Categories at levels 0 and 1 are the root catalog and each store's root category, never shown. */
    public const ROOT_LEVEL = 1;

    private const REST_PATH = '/rest/';
    private const TIME_FORMAT = 'Y-m-d H:i:s';
    private const FIRST_PAGE = 1;
    private const STORE_CODE_PATTERN = '/^[a-z][a-z0-9_]*$/';
    private const ID_PATTERN = '/^\d+$/';
    private const INCREMENTAL = [Kind::Customer, Kind::Order];
    private const SINGLY_READABLE = [Kind::Collection, Kind::Product, Kind::Customer, Kind::Discount, Kind::Order, Kind::Page];

    private ?Node $config = null;
    private ?Node $storeView = null;
    /** @var list<Node>|null */
    private ?array $views = null;
    /** @var list<Node>|null */
    private ?array $groups = null;
    /** @var list<int>|null */
    private ?array $customerGroups = null;
    /** @var array<int, array<array-key, mixed>> attribute id → attribute */
    private array $attributes = [];

    public function __construct(private readonly RestClient $client, private readonly string $storeCode) {}

    public static function connect(string $storeUrl, string $storeCode, #[\SensitiveParameter] string $accessToken, HttpTransport $transport, Sleeper $sleeper): self
    {
        $storeUrl = rtrim(trim($storeUrl), '/');
        if (preg_match('#^https?://[^/]+#', $storeUrl) !== 1) {
            throw new SourceError('Magento is not configured: enter the store URL (https://shop.example).');
        }
        $storeCode = trim($storeCode) === '' ? self::DEFAULT_STORE_CODE : trim($storeCode);
        if (preg_match(self::STORE_CODE_PATTERN, $storeCode) !== 1) {
            throw new SourceError('Magento is not configured: the store view code is letters, digits and underscores (e.g. "default").');
        }
        $authorizer = new MagentoAuthorizer($accessToken);

        return new self(new RestClient('Magento', $storeUrl . self::REST_PATH . $storeCode, $authorizer, $transport, $sleeper), $storeCode);
    }

    public function shop(): Node
    {
        $view = $this->storeView();

        return self::identified('Store', $view->int('id'), [
            ...$this->config()->raw(),
            'store_view' => $view->raw(),
            'store_group' => $this->group()->raw(),
            'website' => $this->website()?->raw(),
            'currency' => $this->required('/V1/directory/currency')->raw(),
        ]);
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        return match ($kind) {
            Kind::Collection => $this->categories($cursor),
            Kind::Product => $this->products($cursor),
            Kind::Customer => $this->customers($cursor, $since),
            Kind::Discount => $this->rules($cursor),
            Kind::Order => $this->orders($cursor, $since),
            Kind::Page => $this->pages($cursor),
            default => throw new SourceError("Magento has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        if (!in_array($kind, self::SINGLY_READABLE, true)) {
            throw new SourceError("Magento can't read a single {$kind->value}.");
        }
        $id = self::numericId($gid);

        return match ($kind) {
            Kind::Collection => $this->category($id),
            Kind::Product => $this->product($id),
            Kind::Customer => $this->one("/V1/customers/{$id}", 'Customer', 'id'),
            Kind::Discount => $this->rule($id),
            Kind::Order => $this->order($id),
            default => $this->one("/V1/cmsPage/{$id}", 'CmsPage', 'id'),
        };
    }

    public function isIncremental(Kind $kind): bool
    {
        return in_array($kind, self::INCREMENTAL, true);
    }

    /** Categories below the store roots, with the store's URLs (their images are store paths). */
    private function categories(?string $cursor): Page
    {
        [$categories, $next] = $this->searchPage('/V1/categories/list', [['level', (string) self::ROOT_LEVEL, 'gt']], 'entity_id', $cursor);

        return self::paged(array_map($this->withStore(...), $categories), $next);
    }

    private function category(string $id): ?Node
    {
        $category = $this->client->get("/V1/categories/{$id}");

        return $category === null || ($category->optionalInt('level') ?? 0) <= self::ROOT_LEVEL ? null : $this->withStore($category);
    }

    private function withStore(Node $category): Node
    {
        return self::identified('Category', $category->int('id'), [...$category->raw(), 'store' => $this->storeContext()]);
    }

    private function products(?string $cursor): Page
    {
        [$products, $next] = $this->searchPage('/V1/products', [['visibility', (string) self::NOT_VISIBLE_INDIVIDUALLY, 'neq']], 'entity_id', $cursor);

        return self::paged(array_map($this->assembleProduct(...), $products), $next);
    }

    private function product(string $id): ?Node
    {
        $product = $this->search('/V1/products', [['entity_id', $id, 'eq']], null, self::FIRST_PAGE)->list('items')[0] ?? null;

        return $product === null || $product->optionalInt('visibility') === self::NOT_VISIBLE_INDIVIDUALLY ? null : $this->assembleProduct($product);
    }

    /** A product with the store's context and, when configurable, its child products and their attributes. */
    private function assembleProduct(Node $product): Node
    {
        $children = [];
        $attributes = [];
        $extension = $product->child('extension_attributes');
        if ($product->optionalString('type_id') === self::CONFIGURABLE && $extension !== null) {
            $links = $extension->ints('configurable_product_links');
            $children = $links === [] ? [] : array_map(
                static fn(Node $child): array => $child->raw(),
                $this->all('/V1/products', [['entity_id', implode(',', $links), 'in']]),
            );
            foreach ($extension->list('configurable_product_options') as $option) {
                $attributes[] = $this->attribute($option->string('attribute_id'));
            }
        }

        return self::identified('Product', $product->int('id'), [
            ...$product->raw(),
            'store' => $this->storeContext(),
            'children' => $children,
            'attributes' => $attributes,
        ]);
    }

    /**
     * A product attribute with its options, read once per run.
     *
     * @return array<array-key, mixed>
     */
    private function attribute(string $attributeId): array
    {
        $id = (int) $attributeId;

        return $this->attributes[$id] ??= $this->required("/V1/products/attributes/{$id}")->raw();
    }

    private function customers(?string $cursor, ?DateTimeImmutable $since): Page
    {
        [$customers, $next] = $this->searchPage('/V1/customers/search', self::changedSince($since), 'entity_id', $cursor);

        return self::paged(array_map(static fn(Node $customer): Node => self::identified('Customer', $customer->int('id'), $customer->raw()), $customers), $next);
    }

    private function rules(?string $cursor): Page
    {
        [$rules, $next] = $this->searchPage('/V1/salesRules/search', [], 'rule_id', $cursor);

        return self::paged($this->assembleRules($rules), $next);
    }

    private function rule(string $id): ?Node
    {
        $rule = $this->client->get("/V1/salesRules/{$id}");

        return $rule === null ? null : $this->assembleRules([$rule])[0];
    }

    /**
     * Cart price rules with their coupons (read for the whole page), the store's website, timezone and every
     * customer group.
     *
     * @param list<Node> $rules
     *
     * @return list<Node>
     */
    private function assembleRules(array $rules): array
    {
        $ids = array_map(static fn(Node $rule): int => $rule->int('rule_id'), $rules);
        $coupons = [];
        foreach ($ids === [] ? [] : $this->all('/V1/coupons/search', [['rule_id', implode(',', $ids), 'in']]) as $coupon) {
            $coupons[$coupon->int('rule_id')][] = $coupon->raw();
        }
        $groups = $this->customerGroups();

        return array_map(fn(Node $rule): Node => self::identified('SalesRule', $rule->int('rule_id'), [
            ...$rule->raw(),
            'coupons' => $coupons[$rule->int('rule_id')] ?? [],
            'store' => $this->storeContext(),
            'all_customer_group_ids' => $groups,
        ]), $rules);
    }

    /** @return list<int> every customer group's id */
    private function customerGroups(): array
    {
        return $this->customerGroups ??= array_map(static fn(Node $group): int => $group->int('id'), $this->all('/V1/customerGroups/search', []));
    }

    /** Orders placed in the store views of the store's website. */
    private function orders(?string $cursor, ?DateTimeImmutable $since): Page
    {
        $filters = [['store_id', implode(',', $this->websiteStoreIds()), 'in'], ...self::changedSince($since)];
        [$orders, $next] = $this->searchPage('/V1/orders', $filters, 'entity_id', $cursor);

        return self::paged($this->assembleOrders($orders), $next);
    }

    private function order(string $id): ?Node
    {
        $order = $this->client->get("/V1/orders/{$id}");

        return $order === null ? null : $this->assembleOrders([$order])[0];
    }

    /**
     * Orders with their credit memos and shipments, each read once for the whole page.
     *
     * @param list<Node> $orders
     *
     * @return list<Node>
     */
    private function assembleOrders(array $orders): array
    {
        $ids = implode(',', array_map(static fn(Node $order): int => $order->int('entity_id'), $orders));
        $memos = $this->byOrder($ids, '/V1/creditmemos');
        $shipments = $this->byOrder($ids, '/V1/shipments');

        return array_map(static function (Node $order) use ($memos, $shipments): Node {
            $id = $order->int('entity_id');

            return self::identified('Order', $id, [...$order->raw(), 'credit_memos' => $memos[$id] ?? [], 'shipments' => $shipments[$id] ?? []]);
        }, $orders);
    }

    /** @return array<int, list<array<array-key, mixed>>> order id → the documents of that order */
    private function byOrder(string $orderIds, string $path): array
    {
        $documents = [];
        foreach ($orderIds === '' ? [] : $this->all($path, [['order_id', $orderIds, 'in']]) as $document) {
            $documents[$document->int('order_id')][] = $document->raw();
        }

        return $documents;
    }

    private function pages(?string $cursor): Page
    {
        [$pages, $next] = $this->searchPage('/V1/cmsPage/search', [], 'page_id', $cursor);

        return self::paged(array_map(static fn(Node $page): Node => self::identified('CmsPage', $page->int('id'), $page->raw()), $pages), $next);
    }

    /**
     * What the mappers need to know about the store: its media and base URLs, website, root categories and
     * weight unit.
     *
     * @return array<string, mixed>
     */
    private function storeContext(): array
    {
        $config = $this->config();
        $roots = array_map(static fn(Node $group): int => $group->int('root_category_id'), $this->groups());

        return [
            'base_url' => $config->optionalString('secure_base_url') ?? $config->string('base_url'),
            'media_url' => $config->optionalString('secure_base_media_url') ?? $config->string('base_media_url'),
            'website_id' => $this->storeView()->int('website_id'),
            'root_category_ids' => array_values(array_unique([self::ROOT_LEVEL, ...$roots])),
            'weight_unit' => $config->optionalString('weight_unit'),
            'timezone' => $config->optionalString('timezone'),
        ];
    }

    private function config(): Node
    {
        return $this->config ??= $this->withCode($this->required('/V1/store/storeConfigs')->list(RestClient::LIST_KEY), 'store configuration');
    }

    private function storeView(): Node
    {
        return $this->storeView ??= $this->withCode($this->views(), 'store view');
    }

    /** @return list<Node> */
    private function views(): array
    {
        return $this->views ??= $this->required('/V1/store/storeViews')->list(RestClient::LIST_KEY);
    }

    /** @return list<Node> */
    private function groups(): array
    {
        return $this->groups ??= $this->required('/V1/store/storeGroups')->list(RestClient::LIST_KEY);
    }

    private function group(): Node
    {
        $groupId = $this->storeView()->int('store_group_id');
        foreach ($this->groups() as $group) {
            if ($group->int('id') === $groupId) {
                return $group;
            }
        }
        throw new SourceError("Magento has no store {$groupId} for the {$this->storeCode} store view.");
    }

    private function website(): ?Node
    {
        $websiteId = $this->storeView()->int('website_id');
        foreach ($this->required('/V1/store/websites')->list(RestClient::LIST_KEY) as $website) {
            if ($website->int('id') === $websiteId) {
                return $website;
            }
        }

        return null;
    }

    /** @return list<int> the ids of every store view of the store's website */
    private function websiteStoreIds(): array
    {
        $websiteId = $this->storeView()->int('website_id');
        $ids = [];
        foreach ($this->views() as $view) {
            if ($view->int('website_id') === $websiteId) {
                $ids[] = $view->int('id');
            }
        }

        return $ids;
    }

    /** @param list<Node> $items */
    private function withCode(array $items, string $what): Node
    {
        foreach ($items as $item) {
            if ($item->string('code') === $this->storeCode) {
                return $item;
            }
        }
        throw new SourceError("Magento has no {$what} for the store view code \"{$this->storeCode}\".");
    }

    /**
     * One page of a search, and the next page number while the items read so far are fewer than its total.
     *
     * @param list<array{0: string, 1: string, 2: string}> $filters
     *
     * @return array{0: list<Node>, 1: ?string}
     */
    private function searchPage(string $path, array $filters, ?string $sortField, ?string $cursor): array
    {
        $page = self::pageNumber($cursor);
        $response = $this->search($path, $filters, $sortField, $page);
        $items = $response->list('items');
        $read = ($page - 1) * self::PAGE_SIZE + count($items);

        return [$items, $items !== [] && $read < $response->int('total_count') ? (string) ($page + 1) : null];
    }

    /**
     * Every item of a search.
     *
     * @param list<array{0: string, 1: string, 2: string}> $filters
     *
     * @return list<Node>
     */
    private function all(string $path, array $filters): array
    {
        $items = [];
        $cursor = null;
        do {
            [$page, $cursor] = $this->searchPage($path, $filters, null, $cursor);
            $items = [...$items, ...$page];
        } while ($cursor !== null);

        return $items;
    }

    /**
     * A search, each filter in its own filter group (so they must all match).
     *
     * @param list<array{0: string, 1: string, 2: string}> $filters [field, value, condition type]
     */
    private function search(string $path, array $filters, ?string $sortField, int $page): Node
    {
        $query = [];
        foreach ($filters as $group => [$field, $value, $condition]) {
            $prefix = "searchCriteria[filterGroups][{$group}][filters][0]";
            $query["{$prefix}[field]"] = $field;
            $query["{$prefix}[value]"] = $value;
            $query["{$prefix}[conditionType]"] = $condition;
        }
        if ($sortField !== null) {
            $query['searchCriteria[sortOrders][0][field]'] = $sortField;
            $query['searchCriteria[sortOrders][0][direction]'] = 'ASC';
        }
        $query['searchCriteria[pageSize]'] = self::PAGE_SIZE;
        $query['searchCriteria[currentPage]'] = $page;

        return $this->required($path, $query);
    }

    /** @return list<array{0: string, 1: string, 2: string}> the updated_at filter of an incremental read */
    private static function changedSince(?DateTimeImmutable $since): array
    {
        return $since === null ? [] : [['updated_at', $since->setTimezone(new DateTimeZone('UTC'))->format(self::TIME_FORMAT), 'gt']];
    }

    private function one(string $path, string $type, string $idField): ?Node
    {
        $object = $this->client->get($path);

        return $object === null ? null : self::identified($type, $object->int($idField), $object->raw());
    }

    /** @param array<string, scalar> $query */
    private function required(string $path, array $query = []): Node
    {
        return $this->client->get($path, $query) ?? throw new SourceError("Magento has no {$path} for this store.");
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

    private static function numericId(string $gid): string
    {
        $id = substr($gid, strrpos($gid, '/') + 1);

        return preg_match(self::ID_PATTERN, $id) === 1 ? $id : throw new SourceError("Not a Magento identity: {$gid}");
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, int $id, array $raw): Node
    {
        $gid = Identity::of(MagentoAdapter::ID, $type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
