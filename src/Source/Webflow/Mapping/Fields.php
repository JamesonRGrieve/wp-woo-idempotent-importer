<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\Webflow\WebflowAdapter;
use IdempotentImporter\Support\Currency;

/** Field readers shared by the Webflow mappers, so each Webflow shape is read in exactly one place. */
final class Fields
{
    /** The CMS field types whose value is a rich text (HTML) body. */
    public const RICH_TEXT = 'RichText';
    public const IMAGE = 'Image';
    public const PLAIN_TEXT = 'PlainText';
    /** Webflow's product types (ec-product-type), in the order the API reference lists them. */
    public const PRODUCT_TYPES = [
        'ff42fee0113744f693a764e3431a9cc2' => 'physical',
        'f22027db68002190aef89a4a2b7ac8a1' => 'digital',
        'c599e43b1a1c34d5a323aedf75d3adf6' => 'service',
        'b6ccc1830db4b1babeb06a9ac5f6dd76' => 'advanced',
    ];
    /** The length of the hash that identifies a customer (Webflow has no customer object; see customerId()). */
    private const CUSTOMER_HASH_LENGTH = 20;

    public static function id(string $type, string $id): string
    {
        return Identity::of(WebflowAdapter::ID, $type, $id);
    }

    public static function sourceId(Node $node): string
    {
        return Identity::sourceId($node->id());
    }

    public static function productId(string $id): string
    {
        return self::id('Product', $id);
    }

    public static function variantId(string $skuId): string
    {
        return self::id('Variant', $skuId);
    }

    public static function categoryId(string $itemId): string
    {
        return self::id('Category', $itemId);
    }

    public static function collectionId(string $id): string
    {
        return self::id('Collection', $id);
    }

    /**
     * A customer's identity. Webflow keeps no customer object for e-commerce buyers, only each order's
     * `customerInfo`, so a customer is identified by their email address (lower-cased), hashed so the address
     * itself never appears in identities and logs.
     */
    public static function customerId(string $email): string
    {
        return self::id('Customer', substr(hash('sha256', strtolower(trim($email))), 0, self::CUSTOMER_HASH_LENGTH));
    }

    /**
     * An amount (`{unit, value, string}`, value in the currency's minor unit, e.g. cents) as an exact decimal
     * in the major unit; zero when absent.
     */
    public static function money(?Node $price): string
    {
        if ($price === null) {
            return '0';
        }
        $value = $price->raw()['value'] ?? null;
        $minor = is_int($value) ? $value : (is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null);
        if ($minor === null) {
            throw new SourceError('A Webflow amount is not a whole number of minor units.');
        }

        return Currency::fromMinorUnits($minor, strtoupper($price->optionalString('unit') ?? $price->string('currency')));
    }

    public static function time(?Node $node, string $field): ?string
    {
        return $node === null ? null : Times::iso($node, $field);
    }

    /**
     * An order address in WooCommerce's shape: the addressee as the first name (Webflow doesn't split it), the
     * two lines, city, state, postcode and country.
     *
     * @return array<string, string>
     */
    public static function address(?Node $address): array
    {
        if ($address === null) {
            return [];
        }

        return array_filter([
            'first_name' => $address->optionalString('addressee'),
            'address_1' => $address->optionalString('line1'),
            'address_2' => $address->optionalString('line2'),
            'city' => $address->optionalString('city'),
            'state' => $address->optionalString('state'),
            'postcode' => $address->optionalString('postalCode'),
            'country' => $address->optionalString('country'),
        ], static fn(?string $value): bool => $value !== null);
    }

    /**
     * A CMS item's publication state: archived items are private, drafts are drafts, the rest published.
     */
    public static function status(Node $item): string
    {
        return match (true) {
            ($item->raw()['isArchived'] ?? false) === true => 'private',
            ($item->raw()['isDraft'] ?? false) === true => 'draft',
            default => 'publish',
        };
    }
}
