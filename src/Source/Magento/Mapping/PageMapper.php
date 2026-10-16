<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Magento\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A CMS page → a WordPress page with its content, its identifier's last segment as the slug, its creation time
 * and meta title and description; its old URL (/<identifier>) redirects to it (the home page has none). An
 * inactive page is a draft. Content heading, layout, meta keywords, sort order, theme settings and update time
 * are mirrored as meta. Magento's content directives ({{media}}, {{widget}}, Page Builder markup) are kept as
 * written.
 */
final class PageMapper implements Mapper
{
    private const VERSION = 1;
    /** Magento's default home page (web/default/cms_home_page); it is served at the store root, not its own URL. */
    private const HOME_IDENTIFIER = 'home';
    private const DETAILS = [
        'identifier', 'content_heading', 'page_layout', 'meta_keywords', 'sort_order', 'layout_update_xml',
        'custom_theme', 'custom_root_template', 'custom_layout_update_xml', 'custom_theme_from', 'custom_theme_to',
        'update_time',
    ];

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
        $title = $node->optionalString('title') ?? $node->string('identifier');
        $identifier = trim($node->string('identifier'), '/');
        $path = LegacyPaths::pathOf('/' . $identifier);
        $slug = basename($identifier);

        return new Desired(Kind::Page, $node->id(), [
            'title' => $title,
            'slug' => $slug !== '' ? $slug : Slugs::fromTitle($title),
            'content_html' => $node->optionalString('content') ?? '',
            'status' => ($node->raw()['active'] ?? true) === false ? 'draft' : 'publish',
            'published_at' => Fields::time($node, 'creation_time'),
            'seo' => ['title' => $node->optionalString('meta_title'), 'description' => $node->optionalString('meta_description')],
            'page' => Fields::details($node, self::DETAILS),
        ], $path === null || $identifier === self::HOME_IDENTIFIER ? [] : [$path]);
    }
}
