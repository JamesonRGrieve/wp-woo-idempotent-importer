<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\FreshBooks\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/**
 * Field readers shared by the FreshBooks mappers. Money is FreshBooks' `{amount, code}`, an exact decimal string
 * and a currency code (www.freshbooks.com/api/invoices). FreshBooks documents no timezone for its timestamps
 * (`updated`, `signup_date`), so only its dates (`create_date`, a payment's `date`) are read as times, at UTC
 * midnight; the timestamps are kept as meta.
 */
final class Fields
{
    public const SOURCE = 'freshbooks';
    public const SOURCE_LABEL = 'FreshBooks';
    /** `vis_state`: 0 active, 1 deleted, 2 archived (www.freshbooks.com/api/items). */
    public const ARCHIVED = 2;
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public static function id(string $type, int|string $id): string
    {
        return Identity::of(self::SOURCE, $type, $id);
    }

    public static function sourceId(Node $node): string
    {
        return Identity::sourceId($node->id());
    }

    /** A text field, trimmed; null when absent or blank. */
    public static function text(Node $node, string $field): ?string
    {
        $value = $node->raw()[$field] ?? null;
        if (is_int($value) || is_float($value)) {
            return Decimal::of($value);
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** A money field's amount, exact; null when absent. */
    public static function money(Node $node, string $field): ?string
    {
        $money = $node->child($field);

        return $money === null ? null : $money->optionalDecimal('amount');
    }

    /** A money field's amount, exact; zero when absent. */
    public static function moneyOrZero(Node $node, string $field): string
    {
        return self::money($node, $field) ?? '0';
    }

    /** A `YYYY-MM-DD` date as the ISO time of its UTC midnight; null when absent. */
    public static function date(Node $node, string $field): ?string
    {
        $value = self::text($node, $field);
        if ($value === null) {
            return null;
        }
        if (preg_match(self::DATE_PATTERN, $value) !== 1) {
            throw new SourceError("{$field} is not a date: {$value}");
        }

        return "{$value}T00:00:00Z";
    }

    /**
     * A client's billing address (`p_` fields) in the store's shape: only the parts it has.
     *
     * @return array<string, string>
     */
    public static function storeAddress(Node $client): array
    {
        return array_filter([
            'address_1' => self::text($client, 'p_street'),
            'address_2' => self::text($client, 'p_street2'),
            'city' => self::text($client, 'p_city'),
            'state' => self::text($client, 'p_province'),
            'postcode' => self::text($client, 'p_code'),
            'country' => self::text($client, 'p_country'),
        ], static fn(?string $value): bool => $value !== null);
    }

    /**
     * A client's billing address in FluentCRM's shape.
     *
     * @return array<string, string>|null
     */
    public static function crmAddress(Node $client): ?array
    {
        return CrmContract::address([
            'address_line_1' => self::text($client, 'p_street'),
            'address_line_2' => self::text($client, 'p_street2'),
            'city' => self::text($client, 'p_city'),
            'state' => self::text($client, 'p_province'),
            'postal_code' => self::text($client, 'p_code'),
            'country' => self::text($client, 'p_country'),
        ]);
    }

    /** A client's phone: business, else mobile, else home. */
    public static function phone(Node $client): ?string
    {
        return self::text($client, 'bus_phone') ?? self::text($client, 'mob_phone') ?? self::text($client, 'home_phone');
    }

    /**
     * The fields of a record present and set, as sent (what a mapper doesn't place natively, kept as meta).
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
