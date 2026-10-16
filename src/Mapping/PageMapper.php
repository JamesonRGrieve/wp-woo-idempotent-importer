<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Shopify\ShopifyNode;

final class PageMapper implements Mapper
{
    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::Page;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(ShopifyNode $node): Desired
    {
        $metafields = Fields::metafields($node);

        return new Desired(Kind::Page, $node->id(), [
            'title' => $node->string('title'),
            'slug' => $node->string('handle'),
            'content_html' => $node->string('body'),
            'status' => $node->bool('isPublished') ? 'publish' : 'draft',
            'published_at' => $node->optionalString('publishedAt') ?? $node->string('createdAt'),
            'template_suffix' => $node->optionalString('templateSuffix'),
            'seo' => Fields::seo(null, $metafields),
            'meta' => $metafields,
        ], [LegacyPaths::page($node->string('handle'))]);
    }
}
