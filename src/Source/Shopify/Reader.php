<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use DateTimeImmutable;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;

/**
 * Reads complete Shopify objects. A page's nodes have every nested connection declared on their EntityQuery
 * followed to the end; any other connection that still has more pages is an error, never a silent
 * truncation.
 */
final class Reader implements SourceReader
{
    /** Set on the shop instead of its policies when the app may not read them; the mapper reports the gap. */
    public const POLICIES_DENIED = 'shopPoliciesDenied';

    private const SHOP_OPERATION = 'Shop';
    private const SHOP_POLICIES_OPERATION = 'ShopPolicies';
    private const POLICIES_FIELD = 'shopPolicies';
    private const INVENTORY_ITEM_OPERATION = 'InventoryItemProduct';
    private const INVENTORY_ITEM_GID = '/InventoryItem/';

    public function __construct(private readonly GraphQlClient $client) {}

    /**
     * The shop with its policies. Policies need a scope of their own (read_legal_policies), so they are read
     * apart: without it the shop still mirrors, with POLICIES_DENIED (Shopify's reason) instead of policies.
     */
    public function shop(): Node
    {
        $shop = $this->client->query(self::SHOP_OPERATION)->requiredChild('shop');
        try {
            $policies = [self::POLICIES_FIELD => $this->client->query(self::SHOP_POLICIES_OPERATION)
                ->requiredChild('shop')->raw()[self::POLICIES_FIELD] ?? null];
        } catch (AccessDenied $e) {
            $policies = [self::POLICIES_DENIED => $e->getMessage()];
        }

        return new Node([...$shop->raw(), ...$policies], 'shop');
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        $source = EntityQueries::for($kind);
        $variables = ['after' => $cursor];
        if ($source->searchable) {
            $variables['query'] = SearchQuery::build($since, $filter);
        }
        $connection = $this->client->query($source->listOperation(), $variables)->requiredChild($source->connection);
        $pageInfo = $connection->requiredChild('pageInfo');
        $nodes = [];
        foreach ($connection->list('nodes') as $node) {
            $nodes[] = $this->complete($source, $node);
        }

        return new Page($nodes, $pageInfo->bool('hasNextPage'), $pageInfo->optionalString('endCursor'));
    }

    /**
     * One object by its GID, or null when Shopify no longer has it. An inventory item's GID (from an
     * inventory webhook) reads the product it belongs to, since stock changes don't touch the product.
     */
    public function node(Kind $kind, string $gid): ?Node
    {
        if (str_contains($gid, self::INVENTORY_ITEM_GID)) {
            $gid = $this->inventoryItemProduct($gid) ?? throw new SourceError("No product for {$gid}.");
        }
        $source = EntityQueries::for($kind);
        $node = $this->client->query($source->nodeOperation(), ['id' => $gid])->child($source->nodeField);

        return $node === null ? null : $this->complete($source, $node);
    }

    public function isIncremental(Kind $kind): bool
    {
        return EntityQueries::for($kind)->isIncremental();
    }

    private function inventoryItemProduct(string $inventoryItemGid): ?string
    {
        return $this->client->query(self::INVENTORY_ITEM_OPERATION, ['id' => $inventoryItemGid])
            ->child('inventoryItem')?->child('variant')?->child('product')?->id();
    }

    private function complete(EntityQuery $source, Node $node): Node
    {
        $data = $node->raw();
        foreach ($source->nested as $nested) {
            $connection = $data[$nested->field] ?? null;
            if (!is_array($connection)) {
                continue;
            }
            while (self::hasNextPage($connection)) {
                $more = $this->client->query($source->pageOperation($nested), [
                    'id' => $node->id(),
                    'after' => (new Node($connection))->requiredChild('pageInfo')->optionalString('endCursor'),
                ])->requiredChild('node')->requiredChild($nested->field)->raw();
                $connection = [
                    'pageInfo' => $more['pageInfo'] ?? [],
                    'nodes' => [...self::nodes($connection), ...self::nodes($more)],
                ];
            }
            $data[$nested->field] = $connection;
        }
        $truncated = self::firstTruncatedPath($data, $node->id());
        if ($truncated !== null) {
            throw new SourceError("{$truncated} has more entries than one query returns, and the importer does not page through it.");
        }

        return new Node($data, $node->id());
    }

    /** @param array<array-key, mixed> $connection */
    private static function hasNextPage(array $connection): bool
    {
        return is_array($connection['pageInfo'] ?? null) && ($connection['pageInfo']['hasNextPage'] ?? false) === true;
    }

    /**
     * @param array<array-key, mixed> $connection
     *
     * @return list<mixed>
     */
    private static function nodes(array $connection): array
    {
        $nodes = $connection['nodes'] ?? [];

        return is_array($nodes) ? array_values($nodes) : [];
    }

    /** @param array<array-key, mixed> $data */
    private static function firstTruncatedPath(array $data, string $path): ?string
    {
        if (self::hasNextPage($data)) {
            return $path;
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $found = self::firstTruncatedPath($value, "{$path}.{$key}");
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
