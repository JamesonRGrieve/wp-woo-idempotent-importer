<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A published blog post → a post: title, slug, its rich content as HTML (see RichContent), excerpt,
 * hashtags as tags, first publication time, cover image as the featured image and SEO title and
 * description; its old URL (/post/<slug>) redirects to it. A post is filed in its first category (WordPress
 * posts here belong to one blog category), else in the site's blog; every category, tag ids, the featured
 * and pinned flags, the writer, reading time, language and related posts are mirrored as meta. Views, likes
 * and comment counts aren't.
 */
final class ArticleMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = [
        'categoryIds', 'tagIds', 'featured', 'pinned', 'memberId', 'contactId', 'minutesToRead', 'language', 'translationId',
        'relatedPostIds', 'commentingEnabled', 'heroImage', 'lastPublishedDate', 'hasUnpublishedChanges', 'preview',
    ];

    public function kind(): Kind
    {
        return Kind::Article;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $title = $node->string('title');
        $category = $node->strings('categoryIds')[0] ?? null;
        $cover = $node->child('media');
        $image = $cover?->child('wixMedia')?->child('image');
        $url = $image?->optionalString('url');
        $excerpt = $node->optionalString('excerpt');

        return new Desired(Kind::Article, $node->id(), [
            'title' => $title,
            'slug' => $node->string('slug'),
            'content_html' => RichContent::html($node->child('richContent')),
            'excerpt_html' => $excerpt === null ? null : Fields::textHtml($excerpt),
            'tags' => $node->strings('hashtags'),
            'status' => 'publish',
            'published_at' => Fields::requiredTime($node, 'firstPublishedDate'),
            'author' => null,
            'blog_gid' => $category === null ? Fields::id('Blog', $node->string('blog_id')) : Fields::id('BlogCategory', $category),
            'image' => $url === null ? null : ['url' => $url, 'alt' => $cover->optionalString('altText') ?? $image->optionalString('altText') ?? $title],
            'seo' => Fields::seo($node->child('seoData')),
            'post' => $node->only(self::DETAILS),
        ], Fields::legacyPaths($node->child('url')?->optionalString('path')));
    }
}
