<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;

/**
 * A blog article → a post in its blog's category: title, content, summary as the excerpt, author,
 * publication time and image as the featured image; its old storefront URL (`/blogs/<blog url>/<url>.html`)
 * redirects to it. An unpublished article is a draft. Its language is mirrored as meta. Lightspeed keeps an
 * article's tags in a separate resource, which isn't read.
 */
final class ArticleMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['language'];

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
        $url = $node->string('url');
        $blogId = Fields::linkedId($node, 'blog') ?? throw new SourceError("Lightspeed article {$node->id()} has no blog.");
        $blogUrl = Fields::text($node, 'blog_url');
        $image = Fields::object($node, 'image');

        return new Desired(Kind::Article, $node->id(), [
            'title' => $title,
            'slug' => Fields::slug($url, $title),
            'content_html' => Fields::text($node, 'content') ?? '',
            'excerpt_html' => Fields::text($node, 'summary'),
            'tags' => [],
            'status' => ($node->raw()['isPublished'] ?? false) === true ? 'publish' : 'draft',
            'published_at' => Fields::time($node, 'publishedAt') ?? Fields::requiredTime($node, 'createdAt'),
            'author' => Fields::text($node, 'author'),
            'blog_gid' => Fields::blogId($blogId),
            'image' => $image === null ? null : ['url' => $image->string('src'), 'alt' => $title],
            'seo' => ['title' => null, 'description' => null],
            'post' => Fields::details($node, self::DETAILS),
        ], $blogUrl === null ? [] : [Fields::articlePath($blogUrl, $url)]);
    }
}
