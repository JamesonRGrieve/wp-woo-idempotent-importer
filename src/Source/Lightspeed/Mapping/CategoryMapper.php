<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A category → a WooCommerce product category: title, the slug its URL ends in, its content (else its short
 * description) as the description, image and parent; its old storefront URL redirects to it. Its long title,
 * short description, visibility, depth, sort order, product sorting and page type are mirrored as meta.
 * Lightspeed keeps no SEO title or description for a category.
 */
final class CategoryMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['fulltitle', 'description', 'isVisible', 'depth', 'path', 'type', 'sortOrder', 'sorting', 'url'];

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
        $title = $node->string('title');
        $url = $node->string('url');
        $image = Fields::object($node, 'image');
        $parent = Fields::linkedId($node, 'parent');

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $title,
            'slug' => Fields::slug($url, $title),
            'description_html' => Fields::text($node, 'content') ?? Fields::text($node, 'description') ?? '',
            'image' => $image === null ? null : ['url' => $image->string('src'), 'alt' => null],
            'seo' => ['title' => null, 'description' => null],
            'parent_gid' => $parent === null ? null : Fields::categoryId($parent),
            'category' => Fields::details($node, self::DETAILS),
        ], [Fields::categoryPath($url)]);
    }
}
