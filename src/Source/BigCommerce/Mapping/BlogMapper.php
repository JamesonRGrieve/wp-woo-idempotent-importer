<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * The store's blog → a post category named and slugged after its blog page ("Blog" at /blog/ when the store
 * has no blog page); the blog page's old URL redirects to it. Its visibility and SEO are mirrored as meta.
 */
final class BlogMapper implements Mapper
{
    public const DEFAULT_NAME = 'Blog';
    public const DEFAULT_PATH = '/blog/';

    private const VERSION = 1;
    private const DETAILS = ['id', 'is_visible', 'channel_id', 'meta_title', 'meta_description', 'meta_keywords', 'search_keywords'];

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
        $page = $node->child('page');
        $name = $page?->optionalString('name') ?? self::DEFAULT_NAME;
        $url = $page?->optionalString('url') ?? self::DEFAULT_PATH;

        return new Desired(Kind::Blog, $node->id(), [
            'name' => $name,
            'slug' => Fields::slug($url, $name),
            'page' => $page === null ? null : Fields::details($page, self::DETAILS),
        ], Fields::legacyPaths($url));
    }
}
