<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * The CMS collection the operator names as the blog → a post category (its name and slug); its posts are
 * articles. Its collection page (`/<slug>`) redirects to the category. Its field definitions are meta.
 */
final class BlogMapper implements Mapper
{
    private const VERSION = 1;

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
        $slug = $node->string('slug');

        return new Desired(Kind::Blog, $node->id(), [
            'name' => $node->string('displayName'),
            'slug' => $slug,
            'description_html' => '',
            'image' => null,
            'seo' => ['title' => null, 'description' => null],
            'singular_name' => $node->optionalString('singularName'),
            'collection_fields' => CollectionMapper::fieldDefinitions($node),
        ], ["/{$slug}"]);
    }
}
