<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A text page → a WordPress page with its content, its URL as the slug, and its creation time as the
 * publication time; its old storefront URL (`/service/<url>`) redirects to it. A hidden page is a draft.
 * Lightspeed keeps no SEO title or description for a text page. Its sort order and lock (whether it can be
 * removed) are mirrored as meta.
 */
final class PageMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['isLocked', 'sortOrder'];

    public function kind(): Kind
    {
        return Kind::Page;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $title = $node->string('title');
        $url = $node->string('url');

        return new Desired(Kind::Page, $node->id(), [
            'title' => $title,
            'slug' => Fields::slug($url, $title),
            'content_html' => Fields::text($node, 'content') ?? '',
            'status' => ($node->raw()['isVisible'] ?? true) === false ? 'draft' : 'publish',
            'published_at' => Fields::time($node, 'createdAt'),
            'seo' => ['title' => null, 'description' => null],
            'page' => Fields::details($node, self::DETAILS),
        ], [Fields::textpagePath($url)]);
    }
}
