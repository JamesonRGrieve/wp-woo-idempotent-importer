<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

/** Typed reads of WordPress options (get_option returns whatever was stored). */
final class Options
{
    public static function int(string $name, int $default = 0): int
    {
        $value = get_option($name, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function string(string $name, string $default = ''): string
    {
        $value = get_option($name, $default);

        return is_string($value) ? $value : $default;
    }

    /** @return array<array-key, mixed> */
    public static function array(string $name): array
    {
        $value = get_option($name, []);

        return is_array($value) ? $value : [];
    }
}
