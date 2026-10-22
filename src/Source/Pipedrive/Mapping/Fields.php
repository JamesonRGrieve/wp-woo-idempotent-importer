<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive\Mapping;

use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Pipedrive\PipedriveAdapter;
use IdempotentImporter\Support\Decimal;

/**
 * Field readers shared by the Pipedrive mappers. Persons, organizations and products carry the account's own
 * fields in `custom_fields` (keyed by the field's generated key); the reader adds the definitions of those
 * fields (`field_definitions`, from the person, organization or product fields API), so their values become
 * FluentCRM custom fields under their names. Times are `YYYY-MM-DDTHH:MM:SSZ` (v2) or `YYYY-MM-DD HH:MM:SS` in
 * UTC (v1).
 */
final class Fields
{
    public const SOURCE_LABEL = 'Pipedrive';
    /** Pipedrive field types → FluentCRM custom field types. */
    private const TYPES = [
        'text' => CrmContract::TEXTAREA,
        'double' => CrmContract::NUMBER,
        'int' => CrmContract::NUMBER,
        'date' => CrmContract::DATE,
        'enum' => CrmContract::SELECT,
        'status' => CrmContract::SELECT,
        'boolean' => CrmContract::SELECT,
        'set' => CrmContract::MULTI_SELECT,
    ];
    private const YES = 'Yes';
    private const NO = 'No';

    public static function id(string $type, int|string $id): string
    {
        return Identity::of(PipedriveAdapter::ID, $type, $id);
    }

    public static function sourceId(Node $node): string
    {
        return Identity::sourceId($node->id());
    }

    public static function time(Node $node, string $field): ?string
    {
        return Times::iso($node, $field);
    }

    /** The primary entry of a list of `{value, primary, label}` (emails, phones), else the first. */
    public static function primary(Node $node, string $field): ?string
    {
        $entries = is_array($node->raw()[$field] ?? null) ? $node->list($field) : [];
        foreach ($entries as $entry) {
            if (($entry->raw()['primary'] ?? false) === true) {
                return $entry->optionalString('value');
            }
        }

        return ($entries[0] ?? null)?->optionalString('value');
    }

    /**
     * A Pipedrive address (`{value, route, street_number, subpremise, locality, admin_area_level_1, postal_code,
     * country}`) in FluentCRM's shape: the street and number as line 1 (else the formatted address), the
     * subpremise as line 2.
     *
     * @return array<string, string>|null
     */
    public static function crmAddress(?Node $address): ?array
    {
        if ($address === null) {
            return null;
        }
        $street = trim(($address->optionalString('street_number') ?? '') . ' ' . ($address->optionalString('route') ?? ''));

        return CrmContract::address([
            'address_line_1' => $street !== '' ? $street : ($address->optionalString('locality') === null ? $address->optionalString('value') : null),
            'address_line_2' => $address->optionalString('subpremise'),
            'city' => $address->optionalString('locality'),
            'state' => $address->optionalString('admin_area_level_1'),
            'postal_code' => $address->optionalString('postal_code'),
            'country' => $address->optionalString('country'),
        ]);
    }

    /**
     * The account's own fields (`custom_fields`, read through `field_definitions`) as FluentCRM custom fields: a
     * choice as its option's label, several choices as theirs, money as its amount and currency, any other
     * structured value (an address, a time range) as JSON text.
     *
     * @return list<array<string, mixed>>
     */
    public static function customFields(Node $node): array
    {
        $values = $node->child('custom_fields');
        $fields = [];
        foreach ($node->list('field_definitions') as $definition) {
            $key = $definition->string('key');
            $pipedriveType = $definition->string('field_type');
            $type = self::TYPES[$pipedriveType] ?? CrmContract::TEXT;
            $labels = [];
            foreach (is_array($definition->raw()['options'] ?? null) ? $definition->list('options') : [] as $option) {
                $labels[(string) $option->int('id')] = $option->string('label');
            }
            if ($pipedriveType === 'boolean') {
                $labels = ['true' => self::YES, 'false' => self::NO];
            }
            $fields[] = CrmContract::customField(PipedriveAdapter::ID, $key, $definition->string('name'), $type, array_values($labels), self::value($values?->raw()[$key] ?? null, $type, $labels));
        }

        return $fields;
    }

    /**
     * @param array<int|string, string> $labels option id (an integer key once PHP has stored it) → label
     *
     * @return string|list<string>|null
     */
    private static function value(mixed $raw, string $type, array $labels): string|array|null
    {
        return match (true) {
            $raw === null, $raw === '', $raw === [] => null,
            $type === CrmContract::MULTI_SELECT => array_map(static fn(mixed $id): string => $labels[self::scalar($id)] ?? self::scalar($id), is_array($raw) ? array_values($raw) : explode(',', self::scalar($raw))),
            $type === CrmContract::SELECT => $labels[self::scalar($raw)] ?? self::scalar($raw),
            is_array($raw) && array_key_exists('value', $raw) => trim(self::scalar($raw['value']) . ' ' . self::scalar($raw['currency'] ?? null)),
            is_array($raw) => (string) json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => self::scalar($raw),
        };
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => Decimal::of($value),
            is_string($value) => $value,
            default => '',
        };
    }

    /**
     * The company user a deal or activity belongs to (`owner`, which the reader adds from every user), else its
     * owner id.
     *
     * @return array{id: string, email: ?string, name: ?string}|null
     */
    public static function owner(Node $node): ?array
    {
        $user = $node->child('owner');
        $id = $user?->optionalInt('id') ?? $node->optionalInt('owner_id');

        return BoardContract::owner($id === null ? null : (string) $id, $user?->optionalString('email'), $user?->optionalString('name'));
    }
}
