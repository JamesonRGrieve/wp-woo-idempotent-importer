<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed\Mapping;

use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Lightspeed\LightspeedAdapter;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/** Field readers shared by the Lightspeed eCom mappers (and its reader), so each Lightspeed shape is read in exactly one place. */
final class Fields
{
    /** Lightspeed's variant weight units (g|kg|oz|lb) → WooCommerce weight units. */
    public const WEIGHT_UNITS = ['g' => 'g', 'kg' => 'kg', 'oz' => 'oz', 'lb' => 'lbs'];
    /** Storefront URL suffix of a product or article page. */
    public const PAGE_SUFFIX = '.html';

    /**
     * An object field Lightspeed sends as `false` when it is unset (an image, a parent, a brand), as a node;
     * null when unset.
     */
    public static function object(Node $node, string $field): ?Node
    {
        return ($node->raw()[$field] ?? false) === false ? null : $node->child($field);
    }

    /**
     * The id of the object a link field names (`{"resource": {"id": 12, "url": …, "link": …}}`); null when the
     * field is unset or links to a list (`"id": false`).
     */
    public static function linkedId(Node $node, string $field): ?int
    {
        $link = self::object($node, $field);
        $resource = $link === null ? null : self::object($link, 'resource');

        return $resource === null || ($resource->raw()['id'] ?? false) === false ? null : $resource->int('id');
    }

    /** Money or a measurement sent as a JSON number or decimal string, exactly; zero when absent. */
    public static function decimalOrZero(Node $node, string $field): string
    {
        return $node->optionalDecimal($field) ?? '0';
    }

    /** An amount Lightspeed leaves at zero when it isn't set (an old price, a cost): null then. */
    public static function setAmount(Node $node, string $field): ?string
    {
        $amount = $node->optionalDecimal($field);

        return Decimal::isZero($amount) ? null : $amount;
    }

    /** The absolute value of an exact decimal (credit notes carry negative amounts). */
    public static function magnitude(string $amount): string
    {
        $amount = Decimal::normalise($amount);

        return str_starts_with($amount, '-') ? substr($amount, 1) : $amount;
    }

    /** An ISO 8601 timestamp as ISO 8601 UTC; null when absent, empty or `false`. */
    public static function time(Node $node, string $field): ?string
    {
        return is_string($node->raw()[$field] ?? null) ? Times::iso($node, $field) : null;
    }

    public static function requiredTime(Node $node, string $field): string
    {
        return Times::requiredIso($node, $field);
    }

    /** A string field Lightspeed may send as `false` or empty when unset; null then. */
    public static function text(Node $node, string $field): ?string
    {
        return is_string($node->raw()[$field] ?? null) ? $node->optionalString($field) : null;
    }

    public static function id(string $type, int|string $id): string
    {
        return Identity::of(LightspeedAdapter::ID, $type, $id);
    }

    /** The Lightspeed id of a node the reader identified (the last part of its identity). */
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
     * A variant's identity. Lightspeed variant ids are stable across edits and are what an order line links to
     * (`variant`); a product without options still has one (default) variant, so the product mapper and the
     * order mapper both use this.
     */
    public static function variantId(int $variantId): string
    {
        return self::id('Variant', $variantId);
    }

    public static function categoryId(int $categoryId): string
    {
        return self::id('Category', $categoryId);
    }

    public static function customerId(int $customerId): string
    {
        return self::id('Customer', $customerId);
    }

    public static function blogId(int $blogId): string
    {
        return self::id('Blog', $blogId);
    }

    /** A variant weight unit in WooCommerce's units; null for one WooCommerce has no equivalent of. */
    public static function weightUnit(?string $unit): ?string
    {
        return self::WEIGHT_UNITS[strtolower(trim($unit ?? ''))] ?? null;
    }

    /**
     * Whether prices are shown with tax: a B2B shop shows them without, a retail (B2C) shop with. $field is
     * the shop's `isB2b`, which the reader injects into products and orders as `shop_is_b2b`.
     */
    public static function pricesIncludeTax(Node $node, string $field = 'shop_is_b2b'): bool
    {
        return ($node->raw()[$field] ?? false) !== true;
    }

    /** A product's storefront path (`/<url>.html`). */
    public static function productPath(string $url): string
    {
        return LegacyPaths::normalise('/' . trim($url, '/') . self::PAGE_SUFFIX);
    }

    /** A category's storefront path (its URL is its whole path in the tree, e.g. `clothes/womens`). */
    public static function categoryPath(string $url): string
    {
        return LegacyPaths::normalise('/' . trim($url, '/'));
    }

    /** A text page's storefront path (`/service/<url>`). */
    public static function textpagePath(string $url): string
    {
        return LegacyPaths::normalise('/service/' . trim($url, '/'));
    }

    /** A blog's storefront path (`/blogs/<url>`). */
    public static function blogPath(string $url): string
    {
        return LegacyPaths::normalise('/blogs/' . trim($url, '/'));
    }

    /** An article's storefront path (`/blogs/<blog url>/<url>.html`). */
    public static function articlePath(string $blogUrl, string $url): string
    {
        return LegacyPaths::normalise('/blogs/' . trim($blogUrl, '/') . '/' . trim($url, '/') . self::PAGE_SUFFIX);
    }

    /** The slug an object's URL ends in ("clothes/womens" → "womens"), else one made from the title. */
    public static function slug(?string $url, string $title): string
    {
        $last = basename(trim($url ?? '', '/'));

        return $last !== '' ? $last : Slugs::fromTitle($title);
    }

    /**
     * An address with Lightspeed's field prefix (`addressBilling`, `addressShipping`, or the shop's bare
     * fields with prefix "") in WooCommerce's shape. The street, house number and extension are one line; the
     * name is split into first and last name only when it is exactly the given first and last name (else it
     * is kept whole as the first name); the state is the region's code when Lightspeed resolved one, else the
     * region as sent; the country is its upper-case ISO code.
     *
     * @return array<string, string>
     */
    public static function address(Node $node, string $prefix, ?string $firstName = null, ?string $lastName = null, ?string $phone = null): array
    {
        $field = static fn(string $name): string => $prefix === '' ? lcfirst($name) : $prefix . $name;
        $name = self::text($node, $field('Name'));
        [$first, $last] = $name !== null && $name === trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ? [$firstName, $lastName] : [$name, null];
        $street = trim(implode(' ', array_filter([
            self::text($node, $field('Street')),
            trim((self::text($node, $field('Number')) ?? '') . (self::text($node, $field('Extension')) ?? '')),
        ], static fn(?string $part): bool => $part !== null && $part !== '')));
        $country = self::object($node, $field('Country'));
        $region = self::object($node, $field('RegionData'));

        return self::filled([
            'first_name' => $first,
            'last_name' => $last,
            'company' => $prefix === 'addressShipping' ? self::text($node, 'addressShippingCompany') : self::text($node, 'companyName'),
            'address_1' => $street,
            'address_2' => self::text($node, $field('Street2')),
            'city' => self::text($node, $field('City')),
            'state' => $region === null ? self::text($node, $field('Region')) : $region->optionalString('code') ?? $region->optionalString('name'),
            'postcode' => self::text($node, $field('Zipcode')),
            'country' => $country === null ? null : strtoupper($country->string('code')),
            'phone' => $phone,
        ]);
    }

    /**
     * A Lightspeed tax rate list (`[{name, rate, amount}]`, rate a fraction) as WooCommerce line taxes; rates
     * that charged nothing are left out.
     *
     * @return list<array{title: string, rate: ?string, amount: string}>
     */
    public static function taxes(Node $node, string $field): array
    {
        $taxes = [];
        foreach (is_array($node->raw()[$field] ?? null) ? $node->list($field) : [] as $tax) {
            if (!Decimal::isZero($tax->optionalDecimal('amount'))) {
                $taxes[] = ['title' => self::taxTitle($tax), 'rate' => $tax->optionalDecimal('rate'), 'amount' => self::decimalOrZero($tax, 'amount')];
            }
        }

        return $taxes;
    }

    public static function taxTitle(Node $tax): string
    {
        return $tax->optionalString('name') ?? 'Tax';
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
