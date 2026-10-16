<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Mapping;

use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Shopify\ShopifyNode;

/** Article → post in its blog's category. */
final class ArticleMapper implements Mapper
{
    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::Article;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(ShopifyNode $node): Desired
    {
        $metafields = Fields::metafields($node);
        $blog = $node->requiredChild('blog');
        $image = $node->child('image');

        return new Desired(Kind::Article, $node->id(), [
            'title' => $node->string('title'),
            'slug' => $node->string('handle'),
            'content_html' => $node->string('body'),
            'excerpt_html' => $node->optionalString('summary'),
            'tags' => $node->strings('tags'),
            'status' => $node->bool('isPublished') ? 'publish' : 'draft',
            'published_at' => $node->optionalString('publishedAt') ?? $node->string('createdAt'),
            'author' => $node->child('author')?->optionalString('name'),
            'blog_gid' => $blog->id(),
            'image' => $image === null ? null : ['url' => $image->string('url'), 'alt' => $image->optionalString('altText')],
            'template_suffix' => $node->optionalString('templateSuffix'),
            'seo' => Fields::seo(null, $metafields),
            'meta' => $metafields,
        ], [LegacyPaths::article($blog->string('handle'), $node->string('handle'))]);
    }
}
