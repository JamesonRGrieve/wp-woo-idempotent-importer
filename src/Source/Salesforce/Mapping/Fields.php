<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Salesforce\SalesforceAdapter;
use IdempotentImporter\Support\Decimal;

/**
 * Field readers shared by the Salesforce mappers. Records are SOQL query results (`{attributes: {type, url},
 * <field>: <value>}`); the reader selects every field the object's describe lists and adds the describes of the
 * org's own (custom, `__c`) fields that hold data (`custom_fields`) and the names of the fields a user can set
 * (`stored_fields`), so fields a mapper doesn't place natively are kept as meta.
 */
final class Fields
{
    public const SOURCE_LABEL = 'Salesforce';
    /** Salesforce field types → FluentCRM custom field types. */
    private const TYPES = [
        'textarea' => CrmContract::TEXTAREA,
        'double' => CrmContract::NUMBER,
        'currency' => CrmContract::NUMBER,
        'percent' => CrmContract::NUMBER,
        'int' => CrmContract::NUMBER,
        'long' => CrmContract::NUMBER,
        'date' => CrmContract::DATE,
        'datetime' => CrmContract::DATE_TIME,
        'picklist' => CrmContract::SELECT,
        'boolean' => CrmContract::SELECT,
        'multipicklist' => CrmContract::MULTI_SELECT,
    ];
    private const YES = 'Yes';
    private const NO = 'No';
    /** A multi-select picklist's values are separated by semicolons. */
    private const MULTIPLE_SEPARATOR = ';';
    /** Record id prefixes (the first three characters say the object). */
    public const CONTACT_PREFIX = '003';
    public const LEAD_PREFIX = '00Q';
    public const ACCOUNT_PREFIX = '001';

    public static function id(string $type, string $id): string
    {
        return Identity::of(SalesforceAdapter::ID, $type, $id);
    }

    public static function sourceId(Node $node): string
    {
        return Identity::sourceId($node->id());
    }

    /** A record's identity type from its id: a contact, a lead or an account; null for anything else. */
    public static function ownerType(?string $id): ?string
    {
        return match (substr($id ?? '', 0, 3)) {
            self::CONTACT_PREFIX => 'Contact',
            self::LEAD_PREFIX => 'Lead',
            self::ACCOUNT_PREFIX => 'Account',
            default => null,
        };
    }

    /** A text field; numbers and booleans as Salesforce spelled them. */
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

    /**
     * Every field a user can set that isn't placed natively or as a custom field, as the record's details.
     *
     * @param list<string> $native
     *
     * @return array<array-key, mixed>
     */
    public static function details(Node $record, array $native): array
    {
        $custom = array_map(static fn(Node $field): string => $field->string('name'), $record->list('custom_fields'));
        $kept = array_diff($record->strings('stored_fields'), $native, $custom);

        return array_filter(array_intersect_key($record->raw(), array_flip($kept)), static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * The org's own fields (from `custom_fields`) as FluentCRM custom fields with this record's values: picklist
     * values as their labels, a checkbox as Yes or No, date-times in FluentCRM's format.
     *
     * @return list<array<string, mixed>>
     */
    public static function customFields(Node $record): array
    {
        $fields = [];
        foreach ($record->list('custom_fields') as $definition) {
            $name = $definition->string('name');
            $salesforceType = $definition->string('type');
            $type = self::TYPES[$salesforceType] ?? CrmContract::TEXT;
            $labels = [];
            foreach ($definition->list('picklistValues') as $option) {
                $labels[$option->string('value')] = $option->optionalString('label') ?? $option->string('value');
            }
            if ($salesforceType === 'boolean') {
                $labels = ['true' => self::YES, 'false' => self::NO];
            }
            $raw = self::text($record, $name);
            $value = match (true) {
                $raw === null => null,
                $type === CrmContract::MULTI_SELECT => array_map(static fn(string $value): string => $labels[$value] ?? $value, explode(self::MULTIPLE_SEPARATOR, $raw)),
                $type === CrmContract::SELECT => $labels[$raw] ?? $raw,
                $type === CrmContract::DATE_TIME => CrmContract::dateTime($raw),
                default => $raw,
            };
            $fields[] = CrmContract::customField(SalesforceAdapter::ID, $name, $definition->string('label'), $type, array_values($labels), $value);
        }

        return $fields;
    }

    /**
     * A compound address's parts (`<prefix>Street`, `City`, `State`, `PostalCode`, `Country`) in FluentCRM's shape;
     * a street's second line is the second address line.
     *
     * @return array<string, string>|null
     */
    public static function crmAddress(Node $record, string $prefix): ?array
    {
        [$line1, $line2] = array_pad(explode("\n", self::text($record, "{$prefix}Street") ?? '', 2), 2, null);

        return CrmContract::address([
            'address_line_1' => $line1,
            'address_line_2' => $line2,
            'city' => self::text($record, "{$prefix}City"),
            'state' => self::text($record, "{$prefix}State"),
            'postal_code' => self::text($record, "{$prefix}PostalCode"),
            'country' => self::text($record, "{$prefix}Country"),
        ]);
    }

    /**
     * The same address in WooCommerce's shape.
     *
     * @return array<string, string>
     */
    public static function storeAddress(Node $record, string $prefix): array
    {
        $address = self::crmAddress($record, $prefix) ?? [];

        return array_filter([
            'address_1' => $address['address_line_1'] ?? null,
            'address_2' => $address['address_line_2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'postcode' => $address['postal_code'] ?? null,
            'country' => $address['country'] ?? null,
        ], static fn(?string $value): bool => $value !== null);
    }
}
