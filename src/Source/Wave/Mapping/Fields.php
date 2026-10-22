<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wave\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * Field readers shared by the Wave mappers. Wave's ids are opaque global ids, base64 of
 * `Business:<uuid>;<Type>:<id>`: an identity carries the record's own id (after its type), else the global id made
 * path-safe. Money is `{value}`, a decimal string; a `Decimal` is a string too.
 */
final class Fields
{
    public const SOURCE = 'wave';
    public const SOURCE_LABEL = 'Wave';
    private const GLOBAL_ID = '/;[A-Za-z]+:([^;]+)$/';

    public static function id(string $type, string $id): string
    {
        return Identity::of(self::SOURCE, $type, $id);
    }

    /** The record's own id within its global id; the global id, path-safe, when it isn't one. */
    public static function recordId(string $globalId): string
    {
        $decoded = base64_decode($globalId, true);
        if ($decoded !== false && preg_match(self::GLOBAL_ID, $decoded, $match) === 1) {
            return $match[1];
        }

        return rtrim(strtr($globalId, '+/', '-_'), '=');
    }

    /** The identity of a record Wave names by its global id (an invoice's customer, an item's product). */
    public static function linked(string $type, Node $record): string
    {
        return self::id($type, self::recordId($record->string('id')));
    }

    public static function text(Node $node, string $field): ?string
    {
        $value = $node->raw()[$field] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** A Money field's value, exact; zero when absent. */
    public static function money(Node $node, string $field): string
    {
        $value = $node->child($field)?->optionalString('value');

        return $value === null ? '0' : Decimal::of($value);
    }

    public static function time(Node $node, string $field): ?string
    {
        return Times::iso($node, $field);
    }

    /** A `YYYY-MM-DD` date as the ISO time of its UTC midnight; null when absent. */
    public static function date(Node $node, string $field): ?string
    {
        $value = self::text($node, $field);

        return $value === null ? null : Times::iso(new Node(['date' => "{$value}T00:00:00Z"]), 'date');
    }

    /**
     * A customer's name when it is a business's: Wave keeps one name per customer, with the contact person's first
     * and last names beside it; a name that is not the person's is the business they are the contact for.
     */
    public static function companyName(Node $customer): ?string
    {
        $name = self::text($customer, 'name');
        $person = trim((self::text($customer, 'firstName') ?? '') . ' ' . (self::text($customer, 'lastName') ?? ''));
        if ($name === null || $person === '') {
            return null;
        }

        return strcasecmp($name, $person) === 0 ? null : $name;
    }

    /** @return array<string, string> an address in the store's shape: only the parts it has */
    public static function storeAddress(?Node $address): array
    {
        if ($address === null) {
            return [];
        }

        return array_filter([
            'address_1' => self::text($address, 'addressLine1'),
            'address_2' => self::text($address, 'addressLine2'),
            'city' => self::text($address, 'city'),
            'state' => self::provinceCode($address),
            'postcode' => self::text($address, 'postalCode'),
            'country' => $address->child('country')?->optionalString('code'),
        ], static fn(?string $value): bool => $value !== null && $value !== '');
    }

    /** A province's code as WooCommerce keeps it: ISO 3166-2's subdivision part (`CA-BC` → `BC`). */
    private static function provinceCode(Node $address): ?string
    {
        $code = $address->child('province')?->optionalString('code');

        return $code === null ? null : (str_contains($code, '-') ? substr($code, (int) strpos($code, '-') + 1) : $code);
    }

    /** @return array<string, string>|null an address in FluentCRM's shape */
    public static function crmAddress(?Node $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return CrmContract::address([
            'address_line_1' => self::text($address, 'addressLine1'),
            'address_line_2' => self::text($address, 'addressLine2'),
            'city' => self::text($address, 'city'),
            'state' => $address->child('province')?->optionalString('name'),
            'postal_code' => self::text($address, 'postalCode'),
            'country' => $address->child('country')?->optionalString('name'),
        ]);
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
