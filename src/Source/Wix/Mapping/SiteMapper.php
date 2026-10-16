<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;

/**
 * The site's properties → store settings: business name (else the site's display name), email, payment
 * currency, time zone and business address. The display name, description, phone, fax, logo, locale,
 * language, categories, business schedule, languages, cookie policy and business type are kept with the
 * store's details. Wix keeps no store-wide weight unit or tax-inclusive setting among its properties, and
 * its store policies are pages, so those settings are left as they are.
 */
final class SiteMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = [
        'siteDisplayName', 'businessName', 'description', 'phone', 'fax', 'logo', 'locale', 'language', 'categories',
        'businessSchedule', 'multilingual', 'consentPolicy', 'businessConfig', 'externalSiteUrl', 'companyId', 'address',
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
        $address = self::address($node->child('address'));

        return new Desired(Kind::Shop, $node->id(), [
            'name' => $node->optionalString('businessName') ?? $node->optionalString('siteDisplayName') ?? '',
            'email' => $node->optionalString('email'),
            'currency' => $node->optionalString('paymentCurrency') ?? throw new SourceError('The Wix site has no payment currency.'),
            'weight_unit' => null,
            'timezone' => $node->optionalString('timeZone'),
            'prices_include_tax' => null,
            'address' => $address === [] ? null : $address,
            'store' => $node->only(self::DETAILS),
            'policies' => [],
        ]);
    }

    /**
     * The business address in WooCommerce's shape (the street line is its number and street name).
     *
     * @return array<string, string>
     */
    private static function address(?Node $address): array
    {
        if ($address === null) {
            return [];
        }
        $country = $address->optionalString('country');
        $street = trim(($address->optionalString('streetNumber') ?? '') . ' ' . ($address->optionalString('street') ?? ''));

        return array_filter([
            'address_1' => $street,
            'address_2' => $address->optionalString('apartmentNumber'),
            'city' => $address->optionalString('city'),
            'state' => Fields::state($address->optionalString('state'), $country),
            'postcode' => $address->optionalString('zip'),
            'country' => $country,
        ], static fn(?string $value): bool => $value !== null && $value !== '');
    }
}
