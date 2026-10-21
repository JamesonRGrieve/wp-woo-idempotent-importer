<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * An e-commerce category (an item of the site's Categories collection) → a WooCommerce product category: name,
 * slug, its rich text as the description and its image; its other fields are kept as meta. Its old URL
 * (`/category/<slug>`) redirects to it. A draft or archived category is still mirrored (its products keep it);
 * its state is meta.
 */
final class CategoryMapper implements Mapper
{
    private const VERSION = 1;
    private const URL_BASE = '/category/';

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
        $content = ItemContent::of($node);

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $content->title,
            'slug' => $content->slug,
            'description_html' => $content->bodyHtml ?? '',
            'image' => $content->image,
            'seo' => ['title' => null, 'description' => null],
            'fields' => $content->fields,
            'state' => Fields::status($node),
        ], [self::URL_BASE . $content->slug]);
    }
}
