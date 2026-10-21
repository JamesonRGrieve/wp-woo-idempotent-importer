<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A CMS collection (other than the e-commerce collections and the blog) → a custom post type: its name, the
 * name of one item, and its slug as the URL base, so items keep their `/<collection>/<item>` paths. Its field
 * definitions (slug, name, type, whether required, help text and validations) are meta.
 */
final class CollectionMapper implements Mapper
{
    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::ContentType;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        return new Desired(Kind::ContentType, $node->id(), [
            'name' => $node->string('displayName'),
            'singular_name' => $node->optionalString('singularName') ?? $node->string('displayName'),
            'slug' => $node->string('slug'),
            'description' => null,
            'collection_fields' => self::fieldDefinitions($node),
        ]);
    }

    /** @return list<array<array-key, mixed>> a collection's field definitions as Webflow gives them */
    public static function fieldDefinitions(Node $collection): array
    {
        return array_map(static fn(Node $field): array => $field->raw(), $collection->list('fields'));
    }
}
