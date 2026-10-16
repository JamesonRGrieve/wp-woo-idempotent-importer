<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Magento\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * The store view → store settings: the store's name (its store group, as the admin names it), base currency,
 * weight unit and timezone. Magento's REST API doesn't expose the store's contact email, address or whether
 * prices are entered with tax, so those settings are left as they are. The store view, website, locale, URLs,
 * display currency, allowed currencies and exchange rates are mirrored as meta. Magento's policy pages are CMS
 * pages.
 */
final class ShopMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = [
        'code', 'locale', 'base_currency_code', 'default_display_currency_code', 'weight_unit', 'base_url',
        'secure_base_url', 'base_link_url', 'secure_base_link_url', 'base_media_url', 'secure_base_media_url',
        'store_view', 'store_group', 'website', 'currency',
    ];

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
            'name' => $node->child('store_group')?->optionalString('name') ?? $node->requiredChild('store_view')->string('name'),
            'email' => null,
            'currency' => $node->string('base_currency_code'),
            'weight_unit' => Fields::weightUnit($node->optionalString('weight_unit')),
            'timezone' => $node->optionalString('timezone'),
            'prices_include_tax' => null,
            'address' => null,
            'store' => Fields::details($node, self::DETAILS),
            'policies' => [],
        ]);
    }
}
