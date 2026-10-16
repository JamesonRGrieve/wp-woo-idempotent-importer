<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Magento\Mapping;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Magento\MagentoAdapter;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/** Field readers shared by the Magento mappers, so each Magento shape is translated in exactly one place. */
final class Fields
{
    /** Magento's storefront URL suffix for products and categories (its default; the setting isn't in the REST API). */
    public const URL_SUFFIX = '.html';
    /** Magento's weight units (store configuration general/locale/weight_unit) → WooCommerce weight units. */
    public const WEIGHT_UNITS = ['kgs' => 'kg', 'lbs' => 'lbs'];
    private const PRODUCT_MEDIA_PATH = 'catalog/product';
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}/';

    /** Money or a quantity (a JSON number, kept exactly as sent), zero when absent. */
    public static function decimalOrZero(Node $node, string $field): string
    {
        return $node->optionalDecimal($field) ?? '0';
    }

    /** An amount Magento leaves at zero when there is none (a discount, a refund): null then. */
    public static function setAmount(Node $node, string $field): ?string
    {
        $amount = $node->optionalDecimal($field);

        return Decimal::isZero($amount) ? null : $amount;
    }

    /** The size of an amount Magento may send negated (an order's discount_amount is negative). */
    public static function magnitude(string $amount): string
    {
        return ltrim(Decimal::normalise($amount), '-');
    }

    /** A whole quantity sent as a JSON number ("2.0000" → 2); a fractional quantity has no WooCommerce equivalent. */
    public static function quantity(Node $node, string $field): int
    {
        $quantity = Decimal::normalise($node->optionalDecimal($field) ?? '0');
        if (preg_match('/^-?\d+$/', $quantity) !== 1) {
            throw new Unsupported("A quantity of {$quantity} isn't whole; WooCommerce quantities are.");
        }

        return (int) $quantity;
    }

    /** A Magento timestamp ("2026-09-01 10:00:00", UTC) as ISO 8601 UTC; null when absent or empty. */
    public static function time(Node $node, string $field): ?string
    {
        return Times::iso($node, $field);
    }

    public static function requiredTime(Node $node, string $field): string
    {
        return Times::requiredIso($node, $field);
    }

    /**
     * The start of a calendar date ("2026-11-30", a rule's from or to date) in the store's timezone, as ISO
     * 8601 UTC, $days later; null when there is no date.
     */
    public static function dayStart(?string $date, ?string $timezone, int $days = 0): ?string
    {
        if ($date === null || preg_match(self::DATE_PATTERN, $date) !== 1) {
            return null;
        }
        try {
            $start = new DateTimeImmutable(substr($date, 0, 10) . ' 00:00:00', new DateTimeZone($timezone ?? 'UTC'));
        } catch (Exception) {
            throw new SourceError("{$date} in {$timezone} is not a date.");
        }

        return $start->modify("+{$days} days")->setTimezone(new DateTimeZone('UTC'))->format(Times::ISO_FORMAT);
    }

    public static function id(string $type, int|string $id): string
    {
        return Identity::of(MagentoAdapter::ID, $type, $id);
    }

    /** The Magento id of a node the reader identified (the last part of its identity). */
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
     * A variant's identity: the id of the Magento product that is bought. A simple, virtual or downloadable
     * product is its own one variant; a configurable product's variants are its child products, which is
     * also what an order's child line names, so the product mapper and the order mapper both use this.
     */
    public static function variantId(int $productId): string
    {
        return self::id('Variant', $productId);
    }

    public static function categoryId(int $categoryId): string
    {
        return self::id('Category', $categoryId);
    }

    /**
     * A product's or category's custom attributes, code → value as sent (strings, or lists for multi-selects).
     *
     * @return array<string, mixed>
     */
    public static function customAttributes(Node $node): array
    {
        $attributes = [];
        foreach ($node->list('custom_attributes') as $attribute) {
            $attributes[$attribute->string('attribute_code')] = $attribute->raw()['value'] ?? null;
        }

        return $attributes;
    }

    /** @param array<string, mixed> $attributes */
    public static function attribute(array $attributes, string $code): ?string
    {
        $value = $attributes[$code] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The old storefront path of a url key or url path ("outerwear/rain-jackets" → "/outerwear/rain-jackets.html"). */
    public static function legacyPath(?string $urlPath): ?string
    {
        return $urlPath === null ? null : LegacyPaths::pathOf('/' . trim($urlPath, '/') . self::URL_SUFFIX);
    }

    /** @return list<string> */
    public static function legacyPaths(?string $urlPath): array
    {
        $path = self::legacyPath($urlPath);

        return $path === null ? [] : [$path];
    }

    /** A product media file ("/c/o/kettle.png") as its URL under the store's media URL. */
    public static function productMediaUrl(string $mediaUrl, string $file): string
    {
        return rtrim($mediaUrl, '/') . '/' . self::PRODUCT_MEDIA_PATH . '/' . ltrim($file, '/');
    }

    /** The store's weight unit in WooCommerce's units; null for a unit WooCommerce has no equivalent of. */
    public static function weightUnit(?string $storeUnit): ?string
    {
        return self::WEIGHT_UNITS[strtolower(trim($storeUnit ?? ''))] ?? null;
    }

    /**
     * A customer or order address in WooCommerce's shape: the first street line, the rest joined as the second,
     * the region by its code (WooCommerce's state codes are ISO 3166-2, as Magento's are), else by name. A
     * customer address sends its region as an object, an order address as region_code and a region name.
     *
     * @return array<string, string>
     */
    public static function address(?Node $address): array
    {
        if ($address === null) {
            return [];
        }
        $street = $address->strings('street');
        $region = is_array($address->raw()['region'] ?? null) ? $address->child('region') : null;

        return self::filled([
            'first_name' => $address->optionalString('firstname'),
            'last_name' => $address->optionalString('lastname'),
            'company' => $address->optionalString('company'),
            'address_1' => $street[0] ?? null,
            'address_2' => implode(', ', array_slice($street, 1)),
            'city' => $address->optionalString('city'),
            'state' => $region === null
                ? $address->optionalString('region_code') ?? $address->optionalString('region')
                : $region->optionalString('region_code') ?? $region->optionalString('region'),
            'postcode' => $address->optionalString('postcode'),
            'country' => $address->optionalString('country_id'),
            'phone' => $address->optionalString('telephone'),
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
        return $node->only($fields);
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
