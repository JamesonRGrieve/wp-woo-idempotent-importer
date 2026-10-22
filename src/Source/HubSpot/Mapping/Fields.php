<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\HubSpot\Mapping;

use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Source\HubSpot\HubSpotAdapter;
use IdempotentImporter\Source\HubSpot\HubSpotReader;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;

/**
 * Field readers shared by the HubSpot mappers. HubSpot objects are `{id, properties, associations, createdAt,
 * updatedAt, archived}`; every property value is a string (or null). The reader asks for every property the
 * account defines, so the ones a mapper doesn't place natively are kept as meta, and adds the definitions of
 * the account's own (not HubSpot-defined) properties, which become FluentCRM custom fields.
 */
final class Fields
{
    public const SOURCE_LABEL = 'HubSpot';
    /** HubSpot's field types (how a property is edited) → FluentCRM custom field types, after its data type (TYPES). */
    private const FIELD_TYPES = [
        'textarea' => CrmContract::TEXTAREA,
        'select' => CrmContract::SELECT,
        'radio' => CrmContract::SELECT,
        'booleancheckbox' => CrmContract::SELECT,
        'checkbox' => CrmContract::MULTI_SELECT,
    ];
    private const TYPES = ['number' => CrmContract::NUMBER, 'date' => CrmContract::DATE, 'datetime' => CrmContract::DATE_TIME];
    /** HubSpot's priorities (deals' `hs_priority`, tasks' `hs_task_priority`) → FluentBoards'; NONE is none. */
    private const PRIORITIES = ['LOW' => BoardContract::LOW, 'MEDIUM' => BoardContract::MEDIUM, 'HIGH' => BoardContract::HIGH];
    /** A multi-select (checkbox) property's values are separated by semicolons. */
    private const MULTIPLE_SEPARATOR = ';';

    public static function id(string $type, string $id): string
    {
        return Identity::of(HubSpotAdapter::ID, $type, $id);
    }

    public static function sourceId(Node $node): string
    {
        return Identity::sourceId($node->id());
    }

    public static function property(Node $object, string $name): ?string
    {
        return $object->requiredChild('properties')->optionalString($name);
    }

    /** A property holding a timestamp (ISO 8601) or a date, as ISO 8601 UTC. */
    public static function time(Node $object, string $name): ?string
    {
        return Times::iso($object->requiredChild('properties'), $name);
    }

    /**
     * Every property the account can set (`stored_properties`, which the reader adds: neither calculated nor
     * read-only, so not HubSpot's own analytics and counters, which change with every visit) that isn't placed
     * natively or as a custom field, as the object's details.
     *
     * @param list<string> $native
     *
     * @return array<array-key, mixed>
     */
    public static function details(Node $object, array $native): array
    {
        $custom = array_map(static fn(Node $definition): string => $definition->string('name'), $object->list('custom_properties'));
        $kept = array_diff($object->strings('stored_properties'), $native, $custom);

        return array_filter(
            array_intersect_key($object->requiredChild('properties')->raw(), array_flip($kept)),
            static fn(mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * The values of the account's own properties (`custom_properties`) by name, for an object FluentBoards keeps
     * them on as meta (it has no custom fields in its free version).
     *
     * @return array<string, string>
     */
    public static function customValues(Node $object): array
    {
        $values = [];
        foreach ($object->list('custom_properties') as $definition) {
            $value = self::property($object, $definition->string('name'));
            if ($value !== null && $value !== '') {
                $values[$definition->string('name')] = $value;
            }
        }

        return $values;
    }

    /**
     * The ids of an association (`associations.<type>.results[].id`), in HubSpot's order.
     *
     * @return list<string>
     */
    public static function associated(Node $object, string $type): array
    {
        return array_map(static fn(Node $association): string => $association->string('id'), $object->child('associations')?->child($type)?->list('results') ?? []);
    }

    /**
     * The account's own properties (from `custom_properties`, the definitions the reader adds) as FluentCRM custom
     * fields with this object's values. Choice values are their option labels.
     *
     * @return list<array<string, mixed>>
     */
    public static function customFields(Node $object): array
    {
        $values = $object->requiredChild('properties');
        $fields = [];
        foreach ($object->list('custom_properties') as $definition) {
            $name = $definition->string('name');
            $type = self::TYPES[$definition->string('type')] ?? self::FIELD_TYPES[$definition->string('fieldType')] ?? CrmContract::TEXT;
            $labels = [];
            foreach ($definition->list('options') as $option) {
                $labels[$option->string('value')] = $option->string('label');
            }
            $raw = $values->optionalString($name);
            $value = match (true) {
                $raw === null => null,
                $type === CrmContract::MULTI_SELECT => array_map(static fn(string $value): string => $labels[$value] ?? $value, array_values(array_filter(explode(self::MULTIPLE_SEPARATOR, $raw), static fn(string $value): bool => $value !== ''))),
                $type === CrmContract::SELECT => $labels[$raw] ?? $raw,
                $type === CrmContract::DATE_TIME => CrmContract::dateTime($raw),
                default => $raw,
            };
            $fields[] = CrmContract::customField(HubSpotAdapter::ID, $name, $definition->string('label'), $type, array_values($labels), $value);
        }

        return $fields;
    }

    public static function priority(?string $value): ?string
    {
        return $value === null ? null : self::PRIORITIES[strtoupper($value)] ?? null;
    }

    /**
     * The HubSpot user a record belongs to (`owner`, which the reader adds from the Owners API), else its owner id.
     *
     * @return array{id: string, email: ?string, name: ?string}|null
     */
    public static function owner(Node $object): ?array
    {
        $owner = $object->child('owner');
        $id = $owner?->optionalString('id') ?? self::property($object, HubSpotReader::OWNER_PROPERTY);
        $name = trim(($owner?->optionalString('firstName') ?? '') . ' ' . ($owner?->optionalString('lastName') ?? ''));

        return BoardContract::owner($id, $owner?->optionalString('email'), $name);
    }
}
