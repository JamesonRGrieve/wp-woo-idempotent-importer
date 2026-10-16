<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A content page (a page, or a raw page served as it is) → a WordPress page with its body, the slug its
 * storefront URL ends in, and its SEO title and description; its old URL redirects to it. A hidden page is
 * a draft; a page for signed-in customers only is private. BigCommerce keeps no publication time, so the
 * page is dated when it is first mirrored. Contact-form pages (a form, not content) and link pages (a menu
 * link) have no WordPress page equivalent. Parent, sort order, home-page flag, channel, content type and
 * keywords are mirrored as meta.
 */
final class PageMapper implements Mapper
{
    private const VERSION = 1;
    private const CONTENT_TYPES = ['page', 'raw'];
    private const DETAILS = ['type', 'parent_id', 'sort_order', 'is_homepage', 'is_customers_only', 'channel_id', 'content_type', 'search_keywords', 'meta_keywords'];

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
        $name = $node->string('name');
        $type = $node->string('type');
        if (!in_array($type, self::CONTENT_TYPES, true)) {
            throw new Unsupported("\"{$name}\" is a BigCommerce {$type} page, which has no WordPress page equivalent.");
        }
        $url = $node->optionalString('url');

        return new Desired(Kind::Page, $node->id(), [
            'title' => $name,
            'slug' => Fields::slug($url, $name),
            'content_html' => $node->optionalString('body') ?? '',
            'status' => self::status($node),
            'published_at' => null,
            'seo' => ['title' => $node->optionalString('meta_title'), 'description' => $node->optionalString('meta_description')],
            'page' => Fields::details($node, self::DETAILS),
        ], Fields::legacyPaths($url));
    }

    private static function status(Node $page): string
    {
        if (($page->raw()['is_visible'] ?? true) === false) {
            return 'draft';
        }

        return ($page->raw()['is_customers_only'] ?? false) === true ? 'private' : 'publish';
    }
}
