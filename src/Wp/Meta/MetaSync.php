<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp\Meta;

use IdempotentShopifyImporter\Mapping\Values;

/**
 * Writes the importer's meta onto any WordPress object: the Shopify identity, mapped scalar fields, and
 * Shopify metafields (one meta key each, with their types alongside). Metafields removed in Shopify are
 * removed here too; meta the importer never wrote is never touched.
 */
final class MetaSync
{
    public const GID = '_shopify_gid';
    public const DELETED = '_shopify_deleted';
    public const METAFIELD_PREFIX = '_shopify_mf_';
    public const METAFIELD_KEYS = '_shopify_mf_keys';
    public const METAFIELD_TYPES = '_shopify_mf_types';
    public const FIELD_PREFIX = '_shopify_';
    public const METAFIELDS_FIELD = 'meta';

    /**
     * The Shopify identity, the metafields, and every mapped field the writer did not consume into a
     * native WordPress field, as `_shopify_<field>` meta. Nothing mapped is ever silently dropped.
     *
     * @param list<string> $consumed fields the writer stored natively
     */
    public static function mirror(MetaTarget $target, string $gid, Values $fields, array $consumed): void
    {
        $values = [self::GID => $gid, self::DELETED => null];
        foreach ($fields->all() as $field => $value) {
            $field = (string) $field;
            if ($field !== self::METAFIELDS_FIELD && !in_array($field, $consumed, true)) {
                $values[self::FIELD_PREFIX . $field] = $value === [] ? null : $value;
            }
        }
        self::fields($target, $values);
        self::metafields($target, $fields->metafields());
    }

    /**
     * Sets each value; a null value deletes the key.
     *
     * @param array<string, mixed> $values meta key => value
     */
    public static function fields(MetaTarget $target, array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null) {
                $target->delete($key);
            } else {
                $target->set($key, self::storable($value));
            }
        }
    }

    /**
     * Meta is stored as strings or arrays: scalars become strings (booleans "1"/"0").
     *
     * @return string|array<array-key, mixed>
     */
    private static function storable(mixed $value): string|array
    {
        return match (true) {
            is_array($value), is_string($value) => $value,
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => (string) wp_json_encode($value),
        };
    }

    /** @param array<string, array{type: string, value: string}> $metafields "<namespace>.<key>" => typed value */
    public static function metafields(MetaTarget $target, array $metafields): void
    {
        $previous = $target->get(self::METAFIELD_KEYS);
        $previous = is_array($previous) ? array_filter($previous, is_string(...)) : [];
        foreach (array_diff($previous, array_keys($metafields)) as $removed) {
            $target->delete(self::METAFIELD_PREFIX . $removed);
        }
        $types = [];
        foreach ($metafields as $name => $metafield) {
            $target->set(self::METAFIELD_PREFIX . $name, $metafield['value']);
            $types[$name] = $metafield['type'];
        }
        self::fields($target, [
            self::METAFIELD_KEYS => $metafields === [] ? null : array_keys($metafields),
            self::METAFIELD_TYPES => $types === [] ? null : $types,
        ]);
    }

    /**
     * The metafields mirrored onto an object, as "<namespace>.<key>" => value.
     *
     * @return array<string, string>
     */
    public static function readMetafields(MetaTarget $target): array
    {
        $keys = $target->get(self::METAFIELD_KEYS);
        $metafields = [];
        foreach (is_array($keys) ? $keys : [] as $name) {
            $value = is_string($name) ? $target->get(self::METAFIELD_PREFIX . $name) : null;
            if (is_string($name) && is_scalar($value)) {
                $metafields[$name] = (string) $value;
            }
        }

        return $metafields;
    }
}
