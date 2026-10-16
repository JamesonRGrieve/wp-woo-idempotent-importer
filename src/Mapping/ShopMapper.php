<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Shopify\ShopifyNode;

/** The shop's settings and its legal policies (each policy becomes a page). */
final class ShopMapper implements Mapper
{
    private const VERSION = 1;
    private const WEIGHT_UNITS = ['KILOGRAMS' => 'kg', 'GRAMS' => 'g', 'POUNDS' => 'lbs', 'OUNCES' => 'oz'];

    public function kind(): Kind
    {
        return Kind::Shop;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(ShopifyNode $node): Desired
    {
        $address = $node->requiredChild('shopAddress');

        return new Desired(Kind::Shop, $node->id(), [
            'name' => $node->string('name'),
            'email' => $node->string('contactEmail'),
            'currency' => $node->string('currencyCode'),
            'weight_unit' => self::WEIGHT_UNITS[$node->string('weightUnit')] ?? 'kg',
            'timezone' => $node->string('ianaTimezone'),
            'prices_include_tax' => $node->bool('taxesIncluded'),
            'order_number_prefix' => $node->string('orderNumberFormatPrefix'),
            'order_number_suffix' => $node->string('orderNumberFormatSuffix'),
            'primary_host' => $node->requiredChild('primaryDomain')->string('host'),
            'address' => [
                'address_1' => $address->optionalString('address1'),
                'address_2' => $address->optionalString('address2'),
                'city' => $address->optionalString('city'),
                'state' => $address->optionalString('provinceCode'),
                'country' => $address->optionalString('countryCodeV2'),
                'postcode' => $address->optionalString('zip'),
            ],
            'policies' => array_map(static fn(ShopifyNode $policy): array => [
                'gid' => $policy->id(),
                'type' => $policy->string('type'),
                'title' => $policy->string('title'),
                'content_html' => $policy->string('body'),
            ], $node->list('shopPolicies')),
        ]);
    }
}
