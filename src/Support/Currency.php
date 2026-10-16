<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Support;

use InvalidArgumentException;

/**
 * ISO 4217 minor units, for sources that send money as an integer count of a currency's smallest unit
 * (cents for CAD, yen for JPY, fils for KWD), converted to an exact decimal string.
 */
final class Currency
{
    /** Minor units of every currency that doesn't have two (ISO 4217, including withdrawn codes sources still send). */
    private const MINOR_UNITS = [
        'BIF' => 0, 'BYR' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0,
        'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0,
        'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        'CLF' => 4, 'UYW' => 4,
    ];
    private const DEFAULT_MINOR_UNITS = 2;
    /** Codes ISO 4217 gives no minor unit (precious metals, funds, testing), and non-ISO codes. */
    private const NO_MINOR_UNIT = ['XAG', 'XAU', 'XBA', 'XBB', 'XBC', 'XBD', 'XDR', 'XPD', 'XPT', 'XSU', 'XTS', 'XUA', 'XXX', 'XUS', 'BTC'];
    private const CODE_PATTERN = '/^[A-Z]{3}$/';

    /** @throws InvalidArgumentException for a code that isn't a currency with a minor unit */
    public static function minorUnits(string $code): int
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1 || in_array($code, self::NO_MINOR_UNIT, true)) {
            throw new InvalidArgumentException("{$code} is not a currency with a minor unit.");
        }

        return self::MINOR_UNITS[$code] ?? self::DEFAULT_MINOR_UNITS;
    }

    /**
     * An amount in the currency's smallest unit as an exact decimal string (1999 CAD → "19.99", 500 JPY → "500").
     *
     * @throws InvalidArgumentException for a code that isn't a currency with a minor unit
     */
    public static function fromMinorUnits(int $amount, string $code): string
    {
        return Decimal::shift((string) $amount, -self::minorUnits($code));
    }
}
