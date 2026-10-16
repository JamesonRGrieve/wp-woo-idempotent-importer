<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

/** A variable product's attributes, gathered from its mapped variants' options. */
final class VariantAttributes
{
    /**
     * Each attribute (in the order variants first use it) with every value it takes, in first-use order.
     *
     * @param list<array<string, mixed>> $variants mapped variants, each with `options` (attribute name → value)
     *
     * @return list<array{name: string, position: int, values: list<string>}>
     */
    public static function fromVariants(array $variants): array
    {
        $attributes = [];
        foreach ($variants as $variant) {
            $options = $variant['options'] ?? [];
            foreach (is_array($options) ? $options : [] as $name => $value) {
                $name = (string) $name;
                $attributes[$name] ??= ['name' => $name, 'position' => count($attributes) + 1, 'values' => []];
                if (is_string($value) && !in_array($value, $attributes[$name]['values'], true)) {
                    $attributes[$name]['values'][] = $value;
                }
            }
        }

        return array_values($attributes);
    }
}
