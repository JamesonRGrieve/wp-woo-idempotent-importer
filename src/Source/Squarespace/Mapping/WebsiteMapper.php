<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Squarespace\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * The website → store settings: title, currency, timezone and the weight unit of its measurement standard
 * (metric → kg, imperial → lbs). Squarespace's website profile has no store email, address (only a country
 * and region) or tax setting, so those settings are left as they are; the URL, site id, language, location
 * and measurement standard are kept with the shop. Squarespace has no store policies in its API.
 */
final class WebsiteMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['url', 'siteId', 'language', 'location', 'measurementStandard'];

    public function kind(): Kind
    {
        return Kind::Shop;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        return new Desired(Kind::Shop, $node->id(), [
            'name' => $node->optionalString('title') ?? '',
            'email' => null,
            'currency' => $node->string('currency'),
            'weight_unit' => Fields::STANDARD_WEIGHT_UNITS[$node->optionalString('measurementStandard') ?? ''] ?? null,
            'timezone' => $node->optionalString('timeZone'),
            'prices_include_tax' => null,
            'address' => null,
            'store' => $node->only(self::DETAILS),
            'policies' => [],
        ]);
    }
}
