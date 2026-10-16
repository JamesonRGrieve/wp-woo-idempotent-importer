<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Squarespace\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A store page (the page a product is listed on) → a WooCommerce product category: its title and URL slug;
 * its old URL (`/<slug>`) redirects to the category. Whether the page is enabled is mirrored as meta.
 * Squarespace's API gives a store page no description, image or SEO fields.
 */
final class StorePageMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['isEnabled'];

    public function kind(): Kind
    {
        return Kind::Collection;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $name = $node->string('title');
        $slug = $node->optionalString('urlSlug');

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $name,
            'slug' => $slug ?? Slugs::fromTitle($name),
            'description_html' => '',
            'image' => null,
            'seo' => ['title' => null, 'description' => null],
            'store_page' => $node->only(self::DETAILS),
        ], $slug === null ? [] : Fields::legacyPaths('/' . $slug));
    }
}
