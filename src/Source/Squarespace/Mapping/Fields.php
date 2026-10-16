<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Squarespace\Mapping;

use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\Squarespace\SquarespaceAdapter;

/** Field readers shared by the Squarespace mappers, so each Squarespace shape is translated in exactly one place. */
final class Fields
{
    /** Squarespace weight units → WooCommerce weight units. */
    public const WEIGHT_UNITS = ['KILOGRAM' => 'kg', 'POUND' => 'lbs'];
    /** A site's measurement standard → the WooCommerce weight unit its products are weighed in. */
    public const STANDARD_WEIGHT_UNITS = ['METRIC' => 'kg', 'IMPERIAL' => 'lbs'];

    private const CURRENCY_CODE = 'currencyCode';

    /**
     * A MonetaryAmount's `value` as an exact decimal string. Squarespace's docs show it as a JSON number,
     * which is read as the decimal its JSON spelled (never through float arithmetic); a decimal string is
     * read as sent.
     */
    public static function money(Node $node, string $field): ?string
    {
        return $node->child($field)?->optionalDecimal('value');
    }

    public static function moneyOrZero(Node $node, string $field): string
    {
        return self::money($node, $field) ?? '0';
    }

    /**
     * A MonetaryAmount's ISO 4217 currency code. Squarespace's docs send it as a code ("USD"); its published
     * schema types it as an object with a `currencyCode`. Either is read.
     */
    public static function currency(Node $amount): ?string
    {
        $currency = $amount->raw()['currency'] ?? null;
        if (is_array($currency)) {
            return $amount->requiredChild('currency')->optionalString(self::CURRENCY_CODE);
        }

        return $amount->optionalString('currency');
    }

    public static function time(Node $node, string $field): ?string
    {
        return Times::iso($node, $field);
    }

    public static function requiredTime(Node $node, string $field): string
    {
        return Times::requiredIso($node, $field);
    }

    public static function id(string $type, string $id): string
    {
        return Identity::of(SquarespaceAdapter::ID, $type, $id);
    }

    /** The Squarespace id of a node the reader identified (the last part of its identity). */
    public static function sourceId(Node $node): string
    {
        $gid = $node->id();

        return substr($gid, strrpos($gid, '/') + 1);
    }

    public static function productId(string $productId): string
    {
        return self::id('Product', $productId);
    }

    public static function storePageId(string $storePageId): string
    {
        return self::id('StorePage', $storePageId);
    }

    public static function profileId(string $profileId): string
    {
        return self::id('Profile', $profileId);
    }

    /**
     * A variant's key: its Squarespace variant id, which is stable across edits and is what an order line
     * names (`variantId`); a download has no variants, so its key is its product id (its order lines name
     * only the product). The product mapper and the order mapper both use this.
     */
    public static function variantKey(string $productId, ?string $variantId): string
    {
        return $variantId ?? $productId;
    }

    public static function variantId(string $productId, ?string $variantId): string
    {
        return self::id('ProductVariant', self::variantKey($productId, $variantId));
    }

    /** @return list<string> the old storefront path of an absolute URL, if there is one */
    public static function legacyPaths(?string $url): array
    {
        $path = LegacyPaths::pathOf($url);

        return $path === null ? [] : [$path];
    }

    /** A product's weight unit in WooCommerce's units; null for a unit Squarespace may add later. */
    public static function weightUnit(?string $unit): ?string
    {
        return self::WEIGHT_UNITS[$unit ?? ''] ?? null;
    }

    /**
     * A Squarespace Address (order billing and shipping, a profile's address) in WooCommerce's shape.
     *
     * @return array<string, string>
     */
    public static function address(?Node $address): array
    {
        if ($address === null) {
            return [];
        }

        return array_filter([
            'first_name' => $address->optionalString('firstName'),
            'last_name' => $address->optionalString('lastName'),
            'address_1' => $address->optionalString('address1'),
            'address_2' => $address->optionalString('address2'),
            'city' => $address->optionalString('city'),
            'state' => $address->optionalString('state'),
            'postcode' => $address->optionalString('postalCode'),
            'country' => $address->optionalString('countryCode'),
            'phone' => $address->optionalString('phone'),
        ], static fn(?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * A string-to-string map Squarespace sends as a JSON object (a variant's attributes).
     *
     * @return array<string, string>
     */
    public static function stringMap(Node $node, string $field): array
    {
        $value = $node->raw()[$field] ?? [];
        if (!is_array($value)) {
            throw new SourceError("{$field} is not an object of strings.");
        }
        $map = [];
        foreach ($value as $name => $text) {
            if (!is_string($text)) {
                throw new SourceError("{$field}.{$name} is not a string.");
            }
            $map[(string) $name] = $text;
        }

        return $map;
    }
}
