<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Ecwid\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A category → a WooCommerce product category: name, slug (custom, else generated), HTML description, its
 * full-size image with its alt text, SEO title and description, and parent; its old storefront URL redirects
 * to it. Whether it is enabled, its sort order, its URL and its origin and external ids are mirrored as meta.
 * Product counts are volatile and not mirrored.
 */
final class CategoryMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['enabled', 'orderBy', 'url', 'origin', 'externalReferenceId'];

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
        $image = $node->optionalString('originalImageUrl');
        $parent = Fields::linkedId($node, 'parentId');

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $name,
            'slug' => Fields::slug($node, $name),
            'description_html' => $node->optionalString('description') ?? '',
            'image' => $image === null ? null : ['url' => $image, 'alt' => $node->child('alt')?->optionalString('main')],
            'seo' => ['title' => $node->optionalString('seoTitle'), 'description' => $node->optionalString('seoDescription')],
            'parent_gid' => $parent === null ? null : Fields::categoryId($parent),
            'category' => $node->only(self::DETAILS),
        ], Fields::legacyPaths($node->optionalString('url')));
    }
}
