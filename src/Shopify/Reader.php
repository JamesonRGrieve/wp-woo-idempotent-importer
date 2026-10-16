<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Shopify;

/**
 * Reads complete Shopify objects. A page's nodes have every nested connection declared on their Source
 * followed to the end; any other connection that still has more pages is an error, never a silent
 * truncation.
 */
final class Reader
{
    public const PAGE_SIZE = 50;
    private const SHOP_OPERATION = 'Shop';
    private const INVENTORY_ITEM_OPERATION = 'InventoryItemProduct';

    public function __construct(private readonly GraphQlClient $client) {}

    public function shop(): ShopifyNode
    {
        return $this->client->query(self::SHOP_OPERATION)->requiredChild('shop');
    }

    public function page(Source $source, ?string $after, ?string $search): Page
    {
        $variables = ['first' => self::PAGE_SIZE, 'after' => $after];
        if ($source->searchable) {
            $variables['query'] = $search;
        }
        $connection = $this->client->query($source->listOperation(), $variables)->requiredChild($source->connection);
        $pageInfo = $connection->requiredChild('pageInfo');
        $nodes = [];
        foreach ($connection->list('nodes') as $node) {
            $nodes[] = $this->complete($source, $node);
        }

        return new Page($nodes, $pageInfo->bool('hasNextPage'), $pageInfo->optionalString('endCursor'));
    }

    /** The product an inventory item belongs to (inventory changes don't touch the product's updated_at). */
    public function inventoryItemProduct(string $inventoryItemGid): ?string
    {
        return $this->client->query(self::INVENTORY_ITEM_OPERATION, ['id' => $inventoryItemGid])
            ->child('inventoryItem')?->child('variant')?->child('product')?->id();
    }

    /** One object by its GID, or null when Shopify no longer has it. */
    public function node(Source $source, string $gid): ?ShopifyNode
    {
        $node = $this->client->query($source->nodeOperation(), ['id' => $gid])->child($source->nodeField);

        return $node === null ? null : $this->complete($source, $node);
    }

    private function complete(Source $source, ShopifyNode $node): ShopifyNode
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
                    'after' => (new ShopifyNode($connection))->requiredChild('pageInfo')->optionalString('endCursor'),
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
            throw new ShopifyError("{$truncated} has more than " . QueryLibrary::NESTED_PAGE_SIZE . ' entries, which the importer cannot page through.');
        }

        return new ShopifyNode($data, $node->id());
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
