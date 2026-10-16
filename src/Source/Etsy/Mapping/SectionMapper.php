<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/** A shop section → a WooCommerce product category (its rank is mirrored as meta). */
final class SectionMapper implements Mapper
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
        $name = (string) Fields::text($node->string('title'));

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $name,
            'slug' => Fields::slug($name),
            'description_html' => '',
            'image' => null,
            'rank' => $node->int('rank'),
        ]);
    }
}
