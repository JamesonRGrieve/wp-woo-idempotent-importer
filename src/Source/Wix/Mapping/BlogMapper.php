<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * The site's blog and each blog category → a post category. The blog itself is "Blog" at /blog (posts in
 * no category belong to it); a category keeps its label, slug, plain-text description and cover image, and
 * its old category page redirects to it. A category's menu position, language and translation group are
 * mirrored as meta; its post count isn't, so a new post doesn't rewrite it.
 */
final class BlogMapper implements Mapper
{
    public const BLOG_NAME = 'Blog';
    public const BLOG_PATH = '/blog';

    private const VERSION = 1;
    private const BLOG_TYPE = '/Blog/';
    private const DETAILS = ['displayPosition', 'language', 'translationId'];

    public function kind(): Kind
    {
        return Kind::Blog;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        if (str_contains($node->id(), self::BLOG_TYPE)) {
            return new Desired(Kind::Blog, $node->id(), [
                'name' => self::BLOG_NAME,
                'slug' => ltrim(self::BLOG_PATH, '/'),
                'description_html' => '',
                'image' => null,
                'seo' => ['title' => null, 'description' => null],
            ], [self::BLOG_PATH]);
        }
        $cover = $node->child('coverImage');
        $url = $cover?->optionalString('url');

        return new Desired(Kind::Blog, $node->id(), [
            'name' => $node->string('label'),
            'slug' => $node->string('slug'),
            'description_html' => Fields::textHtml($node->optionalString('description')),
            'image' => $url === null ? null : ['url' => $url, 'alt' => $cover->optionalString('altText')],
            'seo' => Fields::seo($node->child('seoData')),
            'category' => $node->only(self::DETAILS),
        ], Fields::legacyPaths($node->child('url')?->optionalString('path')));
    }
}
