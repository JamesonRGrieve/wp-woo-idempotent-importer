<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Shopify\ShopifyNode;

/** Navigation menu → WordPress nav menu, items nested as in Shopify. */
final class MenuMapper implements Mapper
{
    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::Menu;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(ShopifyNode $node): Desired
    {
        return new Desired(Kind::Menu, $node->id(), [
            'title' => $node->string('title'),
            'handle' => $node->string('handle'),
            'items' => self::items($node),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function items(ShopifyNode $parent): array
    {
        return array_map(static fn(ShopifyNode $item): array => [
            'title' => $item->string('title'),
            'type' => $item->string('type'),
            'url' => $item->optionalString('url'),
            'resource_gid' => $item->optionalString('resourceId'),
            'children' => self::items($item),
        ], $parent->list('items'));
    }
}
