<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\QuickBooksOnline\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * Field readers shared by the QuickBooks Online mappers. Records are Intuit's data model as JSON: a reference is
 * `{value, name}`, an email `{Address}`, a phone `{FreeFormNumber}`, an address `{Line1…, City,
 * CountrySubDivisionCode, PostalCode, Country, CountryCode}`, amounts JSON numbers (read exactly as spelled), dates
 * `YYYY-MM-DD`, times ISO 8601 with their offset.
 */
final class Fields
{
    public const SOURCE = 'quickbooks_online';
    public const SOURCE_LABEL = 'QuickBooks Online';

    public static function id(string $type, int|string $id): string
    {
        return Identity::of(self::SOURCE, $type, $id);
    }

    public static function sourceId(Node $node): string
    {
        return Identity::sourceId($node->id());
    }

    public static function text(Node $node, string $field): ?string
    {
        $value = $node->raw()[$field] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** An amount, exact; null when absent. */
    public static function amount(Node $node, string $field): ?string
    {
        return $node->optionalDecimal($field);
    }

    /** A reference's id (`{value}`); null when absent. */
    public static function ref(Node $node, string $field): ?string
    {
        return $node->child($field)?->optionalString('value');
    }

    /** A reference's name (`{name}`); null when absent. */
    public static function refName(Node $node, string $field): ?string
    {
        return $node->child($field)?->optionalString('name');
    }

    public static function email(Node $node, string $field): ?string
    {
        $address = $node->child($field)?->optionalString('Address');

        return $address === null || trim($address) === '' ? null : strtolower(trim($address));
    }

    public static function phone(Node $node, string $field): ?string
    {
        $number = $node->child($field)?->optionalString('FreeFormNumber');

        return $number === null || trim($number) === '' ? null : trim($number);
    }

    public static function created(Node $node): ?string
    {
        $meta = $node->child('MetaData');

        return $meta === null ? null : Times::iso($meta, 'CreateTime');
    }

    /** A `YYYY-MM-DD` date as the ISO time of its UTC midnight; null when absent. */
    public static function date(Node $node, string $field): ?string
    {
        $value = self::text($node, $field);

        return $value === null ? null : Times::iso(new Node(['date' => "{$value}T00:00:00Z"]), 'date');
    }

    /**
     * An address in the store's shape: only the parts it has; the country as its code when Intuit gives one.
     *
     * @return array<string, string>
     */
    public static function storeAddress(?Node $address): array
    {
        if ($address === null) {
            return [];
        }

        return array_filter([
            'address_1' => self::text($address, 'Line1'),
            'address_2' => self::text($address, 'Line2'),
            'city' => self::text($address, 'City'),
            'state' => self::text($address, 'CountrySubDivisionCode'),
            'postcode' => self::text($address, 'PostalCode'),
            'country' => self::text($address, 'CountryCode') ?? self::text($address, 'Country'),
        ], static fn(?string $value): bool => $value !== null);
    }

    /** @return array<string, string>|null an address in FluentCRM's shape */
    public static function crmAddress(?Node $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return CrmContract::address([
            'address_line_1' => self::text($address, 'Line1'),
            'address_line_2' => self::text($address, 'Line2'),
            'city' => self::text($address, 'City'),
            'state' => self::text($address, 'CountrySubDivisionCode'),
            'postal_code' => self::text($address, 'PostalCode'),
            'country' => self::text($address, 'Country') ?? self::text($address, 'CountryCode'),
        ]);
    }

    /** Whether a record is active (Intuit's `Active`; absent is active). */
    public static function active(Node $node): bool
    {
        return ($node->raw()['Active'] ?? true) !== false;
    }

    /**
     * Exact sum of amounts; zero for none.
     *
     * @param list<string> $amounts
     */
    public static function sum(array $amounts): string
    {
        return $amounts === [] ? '0' : Decimal::sum($amounts);
    }

    /**
     * The fields of a record present and set, as sent (kept as meta).
     *
     * @param list<string> $fields
     *
     * @return array<array-key, mixed>
     */
    public static function details(Node $node, array $fields): array
    {
        return array_filter($node->only($fields), static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
