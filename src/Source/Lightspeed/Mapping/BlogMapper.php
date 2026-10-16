<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A blog → a post category named after it and slugged by its URL; its old storefront URL (`/blogs/<url>`)
 * redirects to it. Whether it takes comments and its sort order are mirrored as meta.
 */
final class BlogMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['commentable', 'sortOrder'];

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
        $title = $node->string('title');
        $url = $node->string('url');

        return new Desired(Kind::Blog, $node->id(), [
            'name' => $title,
            'slug' => Fields::slug($url, $title),
            'blog' => Fields::details($node, self::DETAILS),
        ], [Fields::blogPath($url)]);
    }
}
