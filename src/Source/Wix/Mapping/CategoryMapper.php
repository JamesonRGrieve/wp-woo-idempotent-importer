<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Wix\Catalog;

/**
 * A V3 catalog category or a V1 catalog collection → a WooCommerce product category: name, slug, plain-text
 * description, image and (for a category) SEO title and description. A category's parent, visibility,
 * breadcrumbs and tree, and a collection's visibility, are mirrored as meta. Item counts aren't mirrored, so
 * adding a product doesn't rewrite its category.
 */
final class CategoryMapper implements Mapper
{
    private const VERSION = 1;
    private const COLLECTION_TYPE = '/Collection/';
    private const CATEGORY_DETAILS = ['visible', 'breadcrumbsInfo', 'parentCategory', 'treeReference', 'managingAppId'];
    private const COLLECTION_DETAILS = ['visible'];

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
        return str_contains($node->id(), self::COLLECTION_TYPE) ? self::collection($node) : self::category($node);
    }

    private static function category(Node $node): Desired
    {
        $image = $node->child('image');
        $url = $image?->optionalString('url');
        $parent = $node->child('parentCategory')?->optionalString('id');

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $node->string('name'),
            'slug' => $node->string('slug'),
            'description_html' => Fields::textHtml($node->optionalString('description')),
            'image' => $url === null ? null : ['url' => $url, 'alt' => $image->optionalString('altText')],
            'seo' => Fields::seo($node->child('seoData')),
            'parent_gid' => $parent === null ? null : Fields::collectionId(Catalog::V3, $parent),
            'category' => $node->only(self::CATEGORY_DETAILS),
        ]);
    }

    private static function collection(Node $node): Desired
    {
        $image = $node->child('media')?->child('mainMedia')?->child('image');
        $url = $image?->optionalString('url');

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $node->string('name'),
            'slug' => $node->string('slug'),
            'description_html' => Fields::textHtml($node->optionalString('description')),
            'image' => $url === null ? null : ['url' => $url, 'alt' => $image->optionalString('altText')],
            'seo' => ['title' => null, 'description' => null],
            'parent_gid' => null,
            'collection' => $node->only(self::COLLECTION_DETAILS),
        ]);
    }
}
