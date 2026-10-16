<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

/** What WooCommerce Square can't sync, reported as warnings on a mapped product (whatever its source). */
final class SquareLimits
{
    /** WooCommerce Square can't sync an item with more variations than this. */
    public const MAX_VARIATIONS = 250;

    /**
     * @param list<array<string, mixed>> $variants mapped variants (each with a nullable 'sku')
     *
     * @return list<string>
     */
    public static function warnings(string $title, array $variants): array
    {
        $warnings = [];
        if (count($variants) > self::MAX_VARIATIONS) {
            $warnings[] = "\"{$title}\" has " . count($variants) . ' variants; WooCommerce Square syncs at most ' . self::MAX_VARIATIONS . '.';
        }
        $missing = count(array_filter($variants, static fn(array $variant): bool => ($variant['sku'] ?? null) === null));
        if ($missing > 0) {
            $warnings[] = "\"{$title}\" has {$missing} variant(s) without a SKU; WooCommerce Square needs one on each.";
        }

        return $warnings;
    }
}
