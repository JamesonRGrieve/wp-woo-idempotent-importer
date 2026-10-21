<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/** A product category (an option of the product category field) → a WooCommerce product category: its label. */
final class CategoryMapper implements Mapper
{
    private const VERSION = 1;

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
        $name = $node->string('label');

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $name,
            'slug' => Slugs::fromTitle($name),
            'description_html' => '',
            'image' => null,
            'seo' => ['title' => null, 'description' => null],
        ]);
    }
}
