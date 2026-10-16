<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Support;

/** Decimal strings as Shopify sends money and rates, kept exact (never through a float). */
final class Decimal
{
    /** "10.50" → "10.5", "10.00" → "10", "-0.0" → "0", "1e2" is rejected by is_numeric callers. */
    public static function normalise(string $value): string
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '+-');
        if (str_contains($digits, '.')) {
            $digits = rtrim(rtrim($digits, '0'), '.');
        }
        $digits = ltrim($digits, '0');
        if ($digits === '' || str_starts_with($digits, '.')) {
            $digits = '0' . $digits;
        }
        if ($digits === '0') {
            return '0';
        }

        return ($negative ? '-' : '') . $digits;
    }

    public static function isZero(?string $value): bool
    {
        return $value === null || self::normalise($value) === '0';
    }

    /** Multiplies by 10^$places exactly by moving the decimal point ("0.155", 2 → "15.5"; "155", -2 → "1.55"). */
    public static function shift(string $value, int $places): string
    {
        $value = self::normalise($value);
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $digits = $whole . $fraction;
        $point = strlen($whole) + $places;
        if ($point < 0) {
            $digits = str_repeat('0', -$point) . $digits;
            $point = 0;
        }
        $digits = str_pad($digits, $point, '0');
        $shifted = substr($digits, 0, $point) . '.' . substr($digits, $point);

        return self::normalise(($negative ? '-' : '') . $shifted);
    }

    /**
     * Exact sum of decimal strings (scaled to integers on the longest fraction).
     *
     * @param list<string> $values
     */
    public static function sum(array $values): string
    {
        $places = 0;
        foreach ($values as $value) {
            $fraction = explode('.', self::normalise($value), 2)[1] ?? '';
            $places = max($places, strlen($fraction));
        }
        $total = 0;
        foreach ($values as $value) {
            $total += (int) self::shift($value, $places);
        }

        return self::shift((string) $total, -$places);
    }

    /** Exact product of a decimal string and an integer ("19.99" × 3 → "59.97"). */
    public static function multiply(string $value, int $factor): string
    {
        $places = strlen(explode('.', self::normalise($value), 2)[1] ?? '');

        return self::shift((string) ((int) self::shift($value, $places) * $factor), -$places);
    }

    /** Exact comparison of two decimal strings: -1, 0 or 1. */
    public static function compare(string $a, string $b): int
    {
        $a = self::normalise($a);
        $b = self::normalise($b);
        $negativeA = str_starts_with($a, '-');
        $negativeB = str_starts_with($b, '-');
        if ($negativeA !== $negativeB) {
            return $negativeA ? -1 : 1;
        }
        $magnitude = self::compareMagnitude(ltrim($a, '-'), ltrim($b, '-'));

        return $negativeA ? -$magnitude : $magnitude;
    }

    private static function compareMagnitude(string $a, string $b): int
    {
        [$wholeA, $fractionA] = array_pad(explode('.', $a, 2), 2, '');
        [$wholeB, $fractionB] = array_pad(explode('.', $b, 2), 2, '');
        if (strlen($wholeA) !== strlen($wholeB)) {
            return strlen($wholeA) <=> strlen($wholeB);
        }
        $width = max(strlen($fractionA), strlen($fractionB));

        return strcmp($wholeA . str_pad($fractionA, $width, '0'), $wholeB . str_pad($fractionB, $width, '0')) <=> 0;
    }
}
