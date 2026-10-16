<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * The shop → store settings: its website name, email, currency, whether prices include tax (a retail shop's
 * do, a B2B shop's don't) and its address. Lightspeed sends the region by name only, which WooCommerce's
 * state codes can't take exactly, so the store's state is left as it is and the region kept as meta with
 * the shop's other details (domains, phone, fax, VAT and chamber-of-commerce numbers, status, currency
 * rate, website title, description and keywords). Lightspeed has no store timezone or weight unit (each
 * variant carries its own unit), and no store policies (its policy pages are text pages).
 */
final class ShopMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = [
        'status', 'isB2b', 'isRetail', 'subDomain', 'mainDomain', 'email', 'phone', 'fax', 'region', 'vatNumber',
        'cocNumber', 'industry', 'currency', 'createdAt',
    ];
    private const WEBSITE_DETAILS = ['name', 'title', 'description', 'keywords'];

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
        $website = $node->requiredChild('website');
        $address = Fields::address($node, '');
        unset($address['state']);

        return new Desired(Kind::Shop, $node->id(), [
            'name' => Fields::text($website, 'name') ?? Fields::text($website, 'title') ?? '',
            'email' => Fields::text($node, 'email'),
            'currency' => $node->requiredChild('currency')->string('shortcode'),
            'weight_unit' => null,
            'timezone' => null,
            'prices_include_tax' => Fields::pricesIncludeTax($node, 'isB2b'),
            'address' => $address === [] ? null : $address,
            'store' => [...Fields::details($node, self::DETAILS), 'website' => Fields::details($website, self::WEBSITE_DETAILS)],
            'policies' => [],
        ]);
    }
}
