<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\QuickBooksOnline\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/** An item category (an item of type `Category`) → a WooCommerce product category: its name; its parent category is meta. */
final class CategoryMapper implements Mapper
{
    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::Collection;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $name = Fields::text($node, 'Name') ?? 'Category ' . Fields::sourceId($node);
        $parent = Fields::ref($node, 'ParentRef');

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $name,
            'slug' => Slugs::fromTitle($name),
            'description_html' => '',
            'image' => null,
            'seo' => ['title' => null, 'description' => null],
            'parent_gid' => $parent === null ? null : Fields::id('Category', $parent),
            'category' => Fields::details($node, ['FullyQualifiedName', 'Active']),
        ]);
    }
}
