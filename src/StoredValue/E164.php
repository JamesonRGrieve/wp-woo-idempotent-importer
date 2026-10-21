<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\StoredValue;

/**
 * A phone number in E.164 (`+` and 8 to 15 digits), as Square Loyalty enrols by
 * (https://developer.squareup.com/docs/loyalty-api/loyalty-accounts). A number written with its country code
 * (`+1 306 555 0177`) is kept; a 10-digit number (or 11 starting with 1) in a store whose country is in the
 * North American Numbering Plan gets +1. Anything else is not guessed at: null.
 */
final class E164
{
    private const MIN_DIGITS = 8;
    private const MAX_DIGITS = 15;
    private const NANP_COUNTRIES = ['US', 'CA'];
    private const NANP_LENGTH = 10;

    public static function of(string $phone, string $storeCountry): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);
        if (str_starts_with(trim($phone), '+')) {
            $length = strlen($digits);

            return $length >= self::MIN_DIGITS && $length <= self::MAX_DIGITS ? "+{$digits}" : null;
        }
        if (!in_array(strtoupper($storeCountry), self::NANP_COUNTRIES, true)) {
            return null;
        }
        if (strlen($digits) === self::NANP_LENGTH + 1 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === self::NANP_LENGTH ? "+1{$digits}" : null;
    }
}
