<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Zoho\ZohoAdapter;
use IdempotentImporter\Support\Decimal;

/**
 * Field readers shared by the Zoho CRM mappers. A record is `{id, <field api name>: <value>}`; the reader asks for
 * every field the module's metadata lists and adds the metadata of the org's own (custom) fields
 * (`custom_fields`) and the names of the fields a user can set (`stored_fields`), so what a mapper doesn't place
 * natively is kept as meta. A lookup is `{id, name}`.
 */
final class Fields
{
    public const SOURCE_LABEL = 'Zoho CRM';
    /** Zoho's field data types → FluentCRM custom field types. */
    private const TYPES = [
        'textarea' => CrmContract::TEXTAREA,
        'integer' => CrmContract::NUMBER,
        'bigint' => CrmContract::NUMBER,
        'double' => CrmContract::NUMBER,
        'currency' => CrmContract::NUMBER,
        'percent' => CrmContract::NUMBER,
        'decimal' => CrmContract::NUMBER,
        'date' => CrmContract::DATE,
        'datetime' => CrmContract::DATE_TIME,
        'picklist' => CrmContract::SELECT,
        'boolean' => CrmContract::SELECT,
        'multiselectpicklist' => CrmContract::MULTI_SELECT,
    ];
    private const CATEGORY_HASH_LENGTH = 16;
    private const YES = 'Yes';
    private const NO = 'No';

    public static function id(string $type, string $id): string
    {
        return Identity::of(ZohoAdapter::ID, $type, $id);
    }

    /**
     * A product category's identity: categories are the values of a picklist, which have no id of their own (and may
     * hold any character), so the value is hashed.
     */
    public static function categoryId(string $value): string
    {
        return self::id('Category', substr(hash('sha256', $value), 0, self::CATEGORY_HASH_LENGTH));
    }

    public static function sourceId(Node $node): string
    {
        return Identity::sourceId($node->id());
    }

    /** A text field; numbers and booleans as Zoho sent them. */
    public static function text(Node $record, string $field): ?string
    {
        $value = $record->raw()[$field] ?? null;

        return match (true) {
            is_string($value) => $value === '' ? null : $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => Decimal::of($value),
            default => null,
        };
    }

    public static function flag(Node $record, string $field): bool
    {
        return ($record->raw()[$field] ?? false) === true;
    }

    public static function time(Node $record, string $field): ?string
    {
        return Times::iso($record, $field);
    }

    /** The id of a lookup field (`{id, name}`). */
    public static function lookupId(Node $record, string $field): ?string
    {
        return is_array($record->raw()[$field] ?? null) ? $record->requiredChild($field)->optionalString('id') : null;
    }

    /**
     * Every field a user can set that isn't placed natively or as a custom field, as the record's details.
     *
     * @param list<string> $native
     *
     * @return array<array-key, mixed>
     */
    public static function details(Node $record, array $native): array
    {
        $custom = array_map(static fn(Node $field): string => $field->string('api_name'), $record->list('custom_fields'));
        $kept = array_diff($record->strings('stored_fields'), $native, $custom);

        return array_filter(array_intersect_key($record->raw(), array_flip($kept)), static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * The org's own fields (from `custom_fields`) as FluentCRM custom fields with this record's values: picklist
     * values as their display values, a checkbox as Yes or No.
     *
     * @return list<array<string, mixed>>
     */
    public static function customFields(Node $record): array
    {
        $fields = [];
        foreach ($record->list('custom_fields') as $definition) {
            $name = $definition->string('api_name');
            $zohoType = strtolower($definition->string('data_type'));
            $type = self::TYPES[$zohoType] ?? CrmContract::TEXT;
            $labels = [];
            foreach (is_array($definition->raw()['pick_list_values'] ?? null) ? $definition->list('pick_list_values') : [] as $option) {
                $labels[$option->optionalString('actual_value') ?? $option->string('display_value')] = $option->string('display_value');
            }
            if ($zohoType === 'boolean') {
                $labels = ['true' => self::YES, 'false' => self::NO];
            }
            $raw = $record->raw()[$name] ?? null;
            $value = match (true) {
                $raw === null, $raw === '', $raw === [] => null,
                $type === CrmContract::MULTI_SELECT && is_array($raw) => array_values(array_map(static fn(mixed $value): string => is_string($value) ? ($labels[$value] ?? $value) : '', $raw)),
                $type === CrmContract::SELECT => $labels[self::text($record, $name) ?? ''] ?? self::text($record, $name),
                $type === CrmContract::DATE_TIME => CrmContract::dateTime((string) self::text($record, $name)),
                is_array($raw) => is_string($raw['name'] ?? null) ? $raw['name'] : (string) json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                default => self::text($record, $name),
            };
            $fields[] = CrmContract::customField(ZohoAdapter::ID, $name, $definition->string('field_label'), $type, array_values($labels), $value);
        }

        return $fields;
    }

    /**
     * An address's parts (`<prefix>Street`, `City`, `State`, then the postal code and country fields named) in
     * FluentCRM's shape.
     *
     * @return array<string, string>|null
     */
    public static function crmAddress(Node $record, string $prefix, string $postalCode, string $country): ?array
    {
        return CrmContract::address([
            'address_line_1' => self::text($record, "{$prefix}Street"),
            'city' => self::text($record, "{$prefix}City"),
            'state' => self::text($record, "{$prefix}State"),
            'postal_code' => self::text($record, $postalCode),
            'country' => self::text($record, $country),
        ]);
    }

    /**
     * The same address in WooCommerce's shape.
     *
     * @return array<string, string>
     */
    public static function storeAddress(Node $record, string $prefix, string $postalCode, string $country): array
    {
        $address = self::crmAddress($record, $prefix, $postalCode, $country) ?? [];

        return array_filter([
            'address_1' => $address['address_line_1'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'postcode' => $address['postal_code'] ?? null,
            'country' => $address['country'] ?? null,
        ], static fn(?string $value): bool => $value !== null);
    }
}
