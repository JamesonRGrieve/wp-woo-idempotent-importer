<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Shopify\Reader;

/**
 * The shop's settings and its legal policies (each policy becomes a page). When the app may not read the
 * policies, `policies` is null (the policy pages are left as they are) and the gap is a warning.
 */
final class ShopMapper implements Mapper
{
    private const VERSION = 2;
    private const POLICIES_GAP = 'Shop policies not mirrored: the app lacks the read_legal_policies access scope (Shopify: %s)';
    /** Policies with a WordPress/WooCommerce page setting of their own. */
    private const POLICY_ROLES = ['PRIVACY_POLICY' => 'privacy', 'TERMS_OF_SERVICE' => 'terms'];

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
        $address = $node->requiredChild('shopAddress');
        $denied = $node->optionalString(Reader::POLICIES_DENIED);

        return new Desired(Kind::Shop, $node->id(), [
            'name' => $node->string('name'),
            'email' => $node->string('contactEmail'),
            'currency' => $node->string('currencyCode'),
            'weight_unit' => Fields::WEIGHT_UNITS[$node->string('weightUnit')] ?? Fields::DEFAULT_WEIGHT_UNIT,
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
            'policies' => $denied === null ? self::policies($node) : null,
        ], warnings: $denied === null ? [] : [sprintf(self::POLICIES_GAP, $denied)]);
    }

    /** @return list<array<string, mixed>> */
    private static function policies(Node $node): array
    {
        return array_map(static fn(Node $policy): array => [
            'gid' => $policy->id(),
            'type' => $policy->string('type'),
            'role' => self::POLICY_ROLES[$policy->string('type')] ?? null,
            'title' => $policy->string('title'),
            'content_html' => $policy->string('body'),
            'legacy_paths' => [LegacyPaths::policy($policy->string('type'))],
        ], $node->list('shopPolicies'));
    }
}
