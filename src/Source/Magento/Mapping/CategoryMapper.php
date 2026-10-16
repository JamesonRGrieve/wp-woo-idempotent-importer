<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Magento\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A category (below a store's root category) → a WooCommerce product category: name, its url key as the slug,
 * description, image and meta title and description; its old URL (url path + .html) redirects to it. Its
 * parent (none when it sits directly under a root), path, level, position, whether it is active and in the
 * menu, and its other attributes (display mode, anchor, layout, sort options) are mirrored as meta. An inactive
 * category is still mirrored: WooCommerce has no hidden categories, and its products keep it.
 */
final class CategoryMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['parent_id', 'path', 'level', 'position', 'is_active', 'include_in_menu', 'available_sort_by', 'created_at', 'updated_at'];
    /** Attributes mapped to WooCommerce fields; the others are mirrored as meta. */
    private const MAPPED_ATTRIBUTES = ['description', 'url_key', 'url_path', 'image', 'meta_title', 'meta_description', 'children_count'];

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
        $attributes = Fields::customAttributes($node);
        $store = $node->requiredChild('store');
        $parent = $node->optionalInt('parent_id') ?? 0;
        $image = Fields::attribute($attributes, 'image');

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $name,
            'slug' => Fields::attribute($attributes, 'url_key') ?? Slugs::fromTitle($name),
            'description_html' => Fields::attribute($attributes, 'description') ?? '',
            'image' => $image === null ? null : ['url' => self::imageUrl($store, $image), 'alt' => null],
            'seo' => ['title' => Fields::attribute($attributes, 'meta_title'), 'description' => Fields::attribute($attributes, 'meta_description')],
            'parent_gid' => in_array($parent, $store->ints('root_category_ids'), true) || $parent === 0 ? null : Fields::categoryId($parent),
            'category' => Fields::details($node, self::DETAILS),
            'attributes' => array_diff_key($attributes, array_flip(self::MAPPED_ATTRIBUTES)),
        ], Fields::legacyPaths(Fields::attribute($attributes, 'url_path') ?? Fields::attribute($attributes, 'url_key')));
    }

    /** A category image: a path on the store ("/media/catalog/category/x.png") or a file under catalog/category. */
    private static function imageUrl(Node $store, string $image): string
    {
        if (preg_match('#^https?://#', $image) === 1) {
            return $image;
        }

        return str_starts_with($image, '/')
            ? rtrim($store->string('base_url'), '/') . $image
            : rtrim($store->string('media_url'), '/') . '/catalog/category/' . $image;
    }
}
