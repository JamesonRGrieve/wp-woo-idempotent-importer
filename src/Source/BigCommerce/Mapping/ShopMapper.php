<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * The store → store settings: name, order email (else the admin email), currency, weight unit, timezone and
 * whether prices are entered with tax. BigCommerce keeps the store address as one free-text display
 * address, which WooCommerce's address fields can't take exactly, so the address settings are left as they
 * are and the display address is kept with the store's other details (domain, URLs, phone, country,
 * language, logo, plan, features). BigCommerce has no store policies; its policy pages are content pages.
 */
final class ShopMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = [
        'domain', 'secure_url', 'control_panel_base_url', 'status', 'first_name', 'last_name', 'address', 'country',
        'country_code', 'phone', 'admin_email', 'order_email', 'favicon_url', 'language', 'currency_symbol',
        'decimal_separator', 'thousands_separator', 'decimal_places', 'currency_symbol_location', 'weight_units',
        'dimension_units', 'plan_name', 'plan_level', 'industry', 'logo', 'store_id', 'default_channel_id',
        'default_site_id', 'features',
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
        $includesTax = $node->raw()['is_price_entered_with_tax'] ?? null;

        return new Desired(Kind::Shop, $node->id(), [
            'name' => $node->optionalString('name') ?? '',
            'email' => $node->optionalString('order_email') ?? $node->optionalString('admin_email'),
            'currency' => $node->string('currency'),
            'weight_unit' => Fields::weightUnit($node->optionalString('weight_units')),
            'timezone' => $node->child('timezone')?->optionalString('name'),
            'prices_include_tax' => is_bool($includesTax) ? $includesTax : null,
            'address' => null,
            'store' => Fields::details($node, self::DETAILS),
            'policies' => [],
        ]);
    }
}
