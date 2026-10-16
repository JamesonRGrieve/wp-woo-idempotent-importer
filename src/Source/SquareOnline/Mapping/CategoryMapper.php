<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\SquareOnline\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A catalog category → a WooCommerce product category, with its first image and its Square Online SEO
 * (permalink as the slug, page title and description). Its parent, type, online visibility and channels
 * are mirrored as meta. A deleted category maps to nothing, so its copy is retired.
 */
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

    public function map(Node $node): ?Desired
    {
        if (Fields::isDeleted($node)) {
            return null;
        }
        $category = $node->requiredChild('category_data');
        $name = $category->optionalString('name') ?? throw new Unsupported('A Square category without a name has no WooCommerce category.');
        $seo = $category->child('ecom_seo_data');
        $parent = $category->child('parent_category')?->optionalString('id');

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $name,
            'slug' => $seo?->optionalString('permalink') ?? Fields::slug($name),
            'description_html' => '',
            'image' => Fields::images($node, $category->strings('image_ids'))[0] ?? null,
            'seo' => ['title' => $seo?->optionalString('page_title'), 'description' => $seo?->optionalString('page_description')],
            'parent_gid' => $parent === null ? null : Fields::id('Category', $parent),
            'category_type' => $category->optionalString('category_type'),
            'is_top_level' => $category->raw()['is_top_level'] ?? null,
            'online_visibility' => $category->raw()['online_visibility'] ?? null,
            'channels' => $category->strings('channels'),
        ]);
    }
}
