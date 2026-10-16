<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\SquareOnline\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;

/**
 * The seller → store settings from the seller (name, currency) and its main location (email, timezone,
 * address), and the main location's return policy as a page. Square has no store-wide weight unit or tax
 * display setting, so those WooCommerce settings are left as they are. The main location's contact and
 * social details, every location and the Square Online sites are mirrored as meta.
 */
final class ShopMapper implements Mapper
{
    private const VERSION = 1;
    private const LOCATION_DETAILS = [
        'name', 'business_name', 'phone_number', 'website_url', 'description', 'logo_url', 'full_format_logo_url',
        'twitter_username', 'instagram_username', 'facebook_url', 'business_hours', 'coordinates', 'mcc', 'type',
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
        $mainId = $node->optionalString('main_location_id');
        $locations = $node->list('locations');
        $main = array_values(array_filter($locations, static fn(Node $location): bool => $location->string('id') === $mainId))[0] ?? $locations[0] ?? null;
        $returnPolicy = $main?->optionalString('return_policy');
        $address = Fields::address($main?->child('address'));

        return new Desired(Kind::Shop, $node->id(), [
            'name' => $node->optionalString('business_name') ?? $main?->optionalString('business_name') ?? $main?->optionalString('name') ?? '',
            'email' => $main?->optionalString('business_email'),
            'currency' => $node->optionalString('currency') ?? $main?->optionalString('currency') ?? throw new SourceError('The Square seller has no currency.'),
            'weight_unit' => null,
            'timezone' => $main?->optionalString('timezone'),
            'prices_include_tax' => null,
            'address' => $address === [] ? null : $address,
            'country' => $node->string('country'),
            'language' => $node->optionalString('language_code'),
            'main_location' => $main === null ? null : array_intersect_key($main->raw(), array_flip(self::LOCATION_DETAILS)),
            'locations' => array_map(static fn(Node $location): array => [
                'id' => $location->string('id'),
                'name' => $location->optionalString('name'),
                'status' => $location->optionalString('status'),
            ], $locations),
            'sites' => array_map(static fn(Node $site): array => [
                'id' => $site->string('id'),
                'title' => $site->optionalString('site_title'),
                'domain' => $site->optionalString('domain'),
                'published' => $site->raw()['is_published'] ?? null,
            ], $node->list('sites')),
            'policies' => $main === null || $returnPolicy === null ? [] : [[
                'gid' => Fields::id('ReturnPolicy', $main->string('id')),
                'type' => 'return_policy',
                'role' => null,
                'title' => 'Return policy',
                'content_html' => Fields::html($returnPolicy),
                'legacy_paths' => [],
            ]],
        ]);
    }
}
