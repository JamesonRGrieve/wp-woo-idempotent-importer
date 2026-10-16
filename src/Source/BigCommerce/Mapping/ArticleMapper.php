<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A blog post → a post in the blog's category: title, body, summary as the excerpt, tags, author, publication
 * time, thumbnail as the featured image and SEO description; its old URL redirects to it. An unpublished post
 * is a draft. The thumbnail is a path on the store, served under its `/product_images/` directory.
 */
final class ArticleMapper implements Mapper
{
    public const IMAGES_DIRECTORY = '/product_images/';

    private const VERSION = 1;
    private const DETAILS = ['meta_keywords', 'preview_url', 'thumbnail_path'];

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
        $url = $node->optionalString('url');
        $image = self::image($node->optionalString('store_url'), $node->optionalString('thumbnail_path'));

        return new Desired(Kind::Article, $node->id(), [
            'title' => $title,
            'slug' => Fields::slug($url, $title),
            'content_html' => $node->optionalString('body') ?? '',
            'excerpt_html' => $node->optionalString('summary'),
            'tags' => $node->strings('tags'),
            'status' => ($node->raw()['is_published'] ?? false) === true ? 'publish' : 'draft',
            'published_at' => Fields::time($node, 'published_date_iso8601') ?? Fields::requiredTime($node->requiredChild('published_date'), 'date'),
            'author' => $node->optionalString('author'),
            'blog_gid' => Fields::id('Blog', $node->string('blog_id')),
            'image' => $image === null ? null : ['url' => $image, 'alt' => $title],
            'seo' => ['title' => null, 'description' => $node->optionalString('meta_description')],
            'post' => Fields::details($node, self::DETAILS),
        ], Fields::legacyPaths($url));
    }

    /** The thumbnail's URL: a full URL as it is, a path under the store's image directory. */
    private static function image(?string $storeUrl, ?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return $storeUrl === null ? null : rtrim($storeUrl, '/') . self::IMAGES_DIRECTORY . ltrim($path, '/');
    }
}
