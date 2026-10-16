<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Source\BigCommerce\BigCommerceAdapter;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/** Field readers shared by the BigCommerce mappers, so each BigCommerce shape is translated in exactly one place. */
final class Fields
{
    /** BigCommerce's store weight units (as the v2 store reports them, lower-cased) → WooCommerce weight units. */
    public const WEIGHT_UNITS = [
        'lbs' => 'lbs', 'lb' => 'lbs', 'pounds' => 'lbs',
        'ounces' => 'oz', 'oz' => 'oz',
        'kgs' => 'kg', 'kg' => 'kg', 'kilograms' => 'kg',
        'grams' => 'g', 'g' => 'g',
    ];

    private const ISO_FORMAT = 'Y-m-d\TH:i:s\Z';
    /** A normalised path with nothing to redirect. */
    private const ROOT_PATH = '/';

    /** Money or a measurement (a decimal string such as "12.5000", or a JSON number), zero when absent. */
    public static function decimalOrZero(Node $node, string $field): string
    {
        return $node->optionalDecimal($field) ?? '0';
    }

    /** An amount BigCommerce leaves at zero when it isn't set (a sale price, a cost): null then. */
    public static function setAmount(Node $node, string $field): ?string
    {
        $amount = $node->optionalDecimal($field);

        return Decimal::isZero($amount) ? null : $amount;
    }

    /** A v3 ISO 8601 or v2 RFC 2822 timestamp as ISO 8601 UTC; null when absent or empty. */
    public static function time(Node $node, string $field): ?string
    {
        $value = $node->optionalString($field);
        if ($value === null) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format(self::ISO_FORMAT);
        } catch (Exception) {
            throw new SourceError("{$field} is not a timestamp: {$value}");
        }
    }

    public static function requiredTime(Node $node, string $field): string
    {
        return self::time($node, $field) ?? throw new SourceError("{$field} is missing.");
    }

    public static function id(string $type, int|string $id): string
    {
        return Identity::of(BigCommerceAdapter::ID, $type, $id);
    }

    /** The BigCommerce id of a node the reader identified (the last part of its identity). */
    public static function sourceId(Node $node): string
    {
        $gid = $node->id();

        return substr($gid, strrpos($gid, '/') + 1);
    }

    public static function productId(int $productId): string
    {
        return self::id('Product', $productId);
    }

    /**
     * A variant's identity. BigCommerce variant ids are stable across edits and are what an order line names
     * (`variant_id`); a product without options has one base variant, so the product mapper and the order
     * mapper both use this.
     */
    public static function variantId(int $variantId): string
    {
        return self::id('Variant', $variantId);
    }

    public static function categoryId(int $categoryId): string
    {
        return self::id('Category', $categoryId);
    }

    /** A storefront URL or path ("/trail-mug/", "https://shop.example/trail-mug/") as its normalised path. */
    public static function path(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $path = LegacyPaths::normalise($url);

        return $path === self::ROOT_PATH ? null : $path;
    }

    /** @return list<string> the old storefront path to redirect, if there is one */
    public static function legacyPaths(?string $url): array
    {
        $path = self::path($url);

        return $path === null ? [] : [$path];
    }

    /** The slug a storefront path ends in ("/kitchen/trail-mug/" → "trail-mug"), else one made from the title. */
    public static function slug(?string $url, string $title): string
    {
        $path = self::path($url);
        $last = $path === null ? '' : basename($path);

        return $last !== '' ? $last : trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    }

    /** The store's weight unit in WooCommerce's units; null for a unit WooCommerce has no equivalent of. */
    public static function weightUnit(?string $storeUnit): ?string
    {
        return self::WEIGHT_UNITS[strtolower(trim($storeUnit ?? ''))] ?? null;
    }

    /**
     * A v2 order address (billing, shipping or shipment) in WooCommerce's shape. BigCommerce sends the state
     * or province by name; it is kept as sent.
     *
     * @return array<string, string>
     */
    public static function orderAddress(?Node $address): array
    {
        if ($address === null) {
            return [];
        }

        return self::filled([
            'first_name' => $address->optionalString('first_name'),
            'last_name' => $address->optionalString('last_name'),
            'company' => $address->optionalString('company'),
            'address_1' => $address->optionalString('street_1'),
            'address_2' => $address->optionalString('street_2'),
            'city' => $address->optionalString('city'),
            'state' => $address->optionalString('state'),
            'postcode' => $address->optionalString('zip'),
            'country' => $address->optionalString('country_iso2'),
            'phone' => $address->optionalString('phone'),
        ]);
    }

    /**
     * A v3 customer address in WooCommerce's shape (state or province by name, as sent).
     *
     * @return array<string, string>
     */
    public static function customerAddress(?Node $address): array
    {
        if ($address === null) {
            return [];
        }

        return self::filled([
            'first_name' => $address->optionalString('first_name'),
            'last_name' => $address->optionalString('last_name'),
            'company' => $address->optionalString('company'),
            'address_1' => $address->optionalString('address1'),
            'address_2' => $address->optionalString('address2'),
            'city' => $address->optionalString('city'),
            'state' => $address->optionalString('state_or_province'),
            'postcode' => $address->optionalString('postal_code'),
            'country' => $address->optionalString('country_code'),
            'phone' => $address->optionalString('phone'),
        ]);
    }

    /**
     * The fields of an object kept as sent, for meta (only those present).
     *
     * @param list<string> $fields
     *
     * @return array<array-key, mixed>
     */
    public static function details(Node $node, array $fields): array
    {
        return array_intersect_key($node->raw(), array_flip($fields));
    }

    /**
     * @param array<string, ?string> $fields
     *
     * @return array<string, string>
     */
    private static function filled(array $fields): array
    {
        return array_filter($fields, static fn(?string $value): bool => $value !== null && $value !== '');
    }
}
