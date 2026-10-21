<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/** A product category (a value of the products' category picklist) → a WooCommerce product category. */
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
        $name = $node->string('display_value');

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $name,
            'slug' => Slugs::fromTitle($name),
            'description_html' => '',
            'image' => null,
            'seo' => ['title' => null, 'description' => null],
        ]);
    }
}
