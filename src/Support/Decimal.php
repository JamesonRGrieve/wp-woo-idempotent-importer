<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Support;

use InvalidArgumentException;

/** Decimal strings as sources send money and rates, kept exact (never through float arithmetic). */
final class Decimal
{
    /** A float's significant digits: 17 always round-trip an IEEE 754 double. */
    private const MAX_SIGNIFICANT_DIGITS = 17;
    private const NUMBER_PATTERN = '/^([+-]?(?:\d+\.?\d*|\.\d+))(?:[eE]([+-]?\d+))?$/';

    /**
     * A JSON number (or a numeric string, exponent allowed) as an exact decimal string: "1e2" → "100",
     * "1.5E-3" → "0.0015", 19.99 → "19.99". A float is read as the shortest decimal that converts back to it
     * exactly, which is the number the JSON text spelled, whatever PHP's precision settings.
     *
     * @throws InvalidArgumentException for a non-finite float or a string that isn't a decimal number
     */
    public static function of(int|float|string $value): string
    {
        if (is_int($value)) {
            return self::normalise((string) $value);
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('A non-finite number is not a decimal.');
            }
            $value = self::shortest($value);
        }
        if (preg_match(self::NUMBER_PATTERN, trim($value), $match) !== 1) {
            throw new InvalidArgumentException("\"{$value}\" is not a decimal number.");
        }

        return self::shift($match[1], (int) ($match[2] ?? '0'));
    }

    /** "10.50" → "10.5", "10.00" → "10", "-0.0" → "0" (no exponent; see of()). */
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

    /** Exact difference of two decimal strings ("10" − "2.5" → "7.5"). */
    public static function subtract(string $a, string $b): string
    {
        $b = self::normalise($b);

        return self::sum([$a, str_starts_with($b, '-') ? substr($b, 1) : "-{$b}"]);
    }

    /** Exact product of a decimal string and an integer ("19.99" × 3 → "59.97"). */
    public static function multiply(string $value, int $factor): string
    {
        $places = strlen(explode('.', self::normalise($value), 2)[1] ?? '');

        return self::shift((string) ((int) self::shift($value, $places) * $factor), -$places);
    }

    /** Exact product of two decimal strings ("12.5" × "0.13" → "1.625"). */
    public static function product(string $a, string $b): string
    {
        $placesA = strlen(explode('.', self::normalise($a), 2)[1] ?? '');
        $placesB = strlen(explode('.', self::normalise($b), 2)[1] ?? '');

        return self::shift((string) ((int) self::shift($a, $placesA) * (int) self::shift($b, $placesB)), -($placesA + $placesB));
    }

    /** Rounds to $places decimals, halves away from zero ("1.625", 2 → "1.63"; "-0.005", 2 → "-0.01"). */
    public static function round(string $value, int $places): string
    {
        $value = self::normalise($value);
        $negative = str_starts_with($value, '-');
        $fraction = explode('.', $value, 2)[1] ?? '';
        if (strlen($fraction) <= $places) {
            return $value;
        }
        $scaled = self::shift(ltrim($value, '-'), $places);
        [$whole, $rest] = array_pad(explode('.', $scaled, 2), 2, '');
        $rounded = (int) $whole + ((int) ($rest[0] ?? '0') >= 5 ? 1 : 0);

        return self::normalise(($negative ? '-' : '') . self::shift((string) $rounded, -$places));
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

    /** The shortest scientific-notation spelling of a float that converts back to exactly that float. */
    private static function shortest(float $value): string
    {
        for ($digits = 0; $digits < self::MAX_SIGNIFICANT_DIGITS; ++$digits) {
            $spelled = sprintf('%.' . $digits . 'e', $value);
            if ((float) $spelled === $value) {
                return $spelled;
            }
        }

        return sprintf('%.' . (self::MAX_SIGNIFICANT_DIGITS - 1) . 'e', $value);
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
