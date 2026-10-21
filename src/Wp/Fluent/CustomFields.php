<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Fluent;

use FluentCrm\App\Models\CustomCompanyField;
use FluentCrm\App\Models\CustomContactField;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\SubscriberMeta;
use IdempotentImporter\Mapping\Values;

/**
 * A source's custom fields as FluentCRM custom fields: each definition (key, label, type, choices) is added to
 * FluentCRM's contact or company fields, grouped under the source's name, and changed only when the source's
 * definition changed; a contact's values are FluentCRM's custom field values (`fc_subscriber_meta`, object type
 * `custom_field`), a company's its `custom_values`. Only the fields the importer defines are ever written or
 * removed; fields made in FluentCRM are left alone.
 *
 * A mapped custom field is `{key, label, type, options, value}`: key is the FluentCRM slug (`<source>_<field>`),
 * type one of FluentCRM's field types, options the choices of a choice field, value a string, a list of
 * strings for a multi-choice field, or null when the object has no value.
 */
final class CustomFields
{
    private const CUSTOM_FIELD_TYPE = 'custom_field';
    private const MULTIPLE_TYPES = ['select-multi', 'checkbox'];
    private const CHOICE_TYPES = ['select-one', 'select-multi', 'radio', 'checkbox'];

    /**
     * Adds or updates the definitions; returns the values by slug.
     *
     * @param list<Values> $fields
     *
     * @return array<string, string|list<string>|null>
     */
    public static function define(bool $ofCompany, string $group, array $fields): array
    {
        $model = $ofCompany ? new CustomCompanyField() : new CustomContactField();
        $global = $model->getGlobalFields();
        $existing = is_array($global) && is_array($global['fields'] ?? null) ? array_values($global['fields']) : [];
        $bySlug = [];
        foreach ($existing as $index => $definition) {
            if (is_array($definition) && is_string($definition['slug'] ?? null)) {
                $bySlug[$definition['slug']] = $index;
            }
        }
        $changed = false;
        $values = [];
        foreach ($fields as $field) {
            $slug = $field->string('key');
            $definition = ['label' => $field->string('label'), 'slug' => $slug, 'type' => $field->string('type'), 'group' => $group];
            if (in_array($definition['type'], self::CHOICE_TYPES, true)) {
                $definition['options'] = $field->strings('options');
            }
            $index = $bySlug[$slug] ?? null;
            if ($index === null) {
                $existing[] = $definition;
                $bySlug[$slug] = count($existing) - 1;
                $changed = true;
            } elseif (is_array($existing[$index]) && array_intersect_key($existing[$index], $definition) !== $definition) {
                $existing[$index] = [...$existing[$index], ...$definition];
                $changed = true;
            }
            $raw = $field->raw('value');
            $values[$slug] = match (true) {
                $raw === null, $raw === '', $raw === [] => null,
                in_array($definition['type'], self::MULTIPLE_TYPES, true) => $field->strings('value'),
                default => $field->string('value'),
            };
        }
        if ($changed) {
            $model->saveGlobalFields($existing);
        }

        return $values;
    }

    /**
     * A contact's values of the importer's fields: set, changed, or removed when the source has none.
     *
     * @param array<string, string|list<string>|null> $values
     */
    public static function writeContactValues(Subscriber $contact, array $values): void
    {
        $contactId = FluentCrm::id($contact);
        foreach ($values as $slug => $value) {
            $row = (new SubscriberMeta())->newQuery()
                ->where('subscriber_id', $contactId)
                ->where('object_type', self::CUSTOM_FIELD_TYPE)
                ->where('key', $slug)
                ->first();
            $row = $row instanceof SubscriberMeta ? $row : null;
            if ($value === null) {
                $row?->delete();
                continue;
            }
            if ($row === null) {
                $row = new SubscriberMeta();
                $row->fill(['subscriber_id' => $contactId, 'object_type' => self::CUSTOM_FIELD_TYPE, 'key' => $slug, 'value' => $value, 'created_by' => 0]);
                $row->save();
            } elseif ($row->getAttribute('value') !== $value) {
                $row->fill(['value' => $value]);
                $row->save();
            }
        }
    }

    /**
     * A company's `custom_values`, the importer's fields replaced and the rest kept.
     *
     * @param array<array-key, mixed> $current
     * @param array<string, string|list<string>|null> $values
     *
     * @return array<array-key, mixed>
     */
    public static function companyValues(array $current, array $values): array
    {
        foreach ($values as $slug => $value) {
            if ($value === null) {
                unset($current[$slug]);
            } else {
                $current[$slug] = $value;
            }
        }

        return $current;
    }
}
