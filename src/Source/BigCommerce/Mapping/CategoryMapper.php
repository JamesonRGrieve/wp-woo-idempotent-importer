<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A category (of any category tree) → a WooCommerce product category: name, the slug its storefront URL
 * ends in, description, image and SEO title and description; its old URL redirects to it. Its parent,
 * tree, visibility, sort order, keywords, layout and default product sort are mirrored as meta.
 */
final class CategoryMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = [
        'category_uuid', 'tree_id', 'is_visible', 'sort_order', 'meta_keywords', 'search_keywords', 'layout_file',
        'default_product_sort', 'url',
    ];
    private const NO_PARENT = 0;

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
        $name = $node->string('name');
        $url = $node->child('url')?->optionalString('path');
        $image = $node->optionalString('image_url');
        $parent = $node->optionalInt('parent_id') ?? self::NO_PARENT;

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $name,
            'slug' => Fields::slug($url, $name),
            'description_html' => $node->optionalString('description') ?? '',
            'image' => $image === null ? null : ['url' => $image, 'alt' => null],
            'seo' => ['title' => $node->optionalString('page_title'), 'description' => $node->optionalString('meta_description')],
            'parent_gid' => $parent === self::NO_PARENT ? null : Fields::categoryId($parent),
            'category' => Fields::details($node, self::DETAILS),
        ], Fields::legacyPaths($url));
    }
}
