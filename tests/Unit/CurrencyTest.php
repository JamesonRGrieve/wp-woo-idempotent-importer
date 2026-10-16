<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Support\Currency;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    /** @return iterable<string, array{int, string, string}> */
    public static function amounts(): iterable
    {
        yield 'cents' => [1999, 'CAD', '19.99'];
        yield 'whole dollars' => [3200, 'USD', '32'];
        yield 'one cent' => [1, 'EUR', '0.01'];
        yield 'zero-decimal yen' => [500, 'JPY', '500'];
        yield 'zero-decimal won' => [12000, 'KRW', '12000'];
        yield 'three-decimal dinar' => [1250, 'KWD', '1.25'];
        yield 'four-decimal unit of account' => [12345, 'CLF', '1.2345'];
        yield 'negative' => [-505, 'GBP', '-5.05'];
        yield 'zero' => [0, 'AUD', '0'];
    }

    #[DataProvider('amounts')]
    public function testMinorUnitsBecomeAnExactDecimal(int $amount, string $code, string $expected): void
    {
        self::assertSame($expected, Currency::fromMinorUnits($amount, $code));
    }

    public function testAnAmountBeyondFloatPrecisionStaysExact(): void
    {
        self::assertSame('92233720368547758.07', Currency::fromMinorUnits(PHP_INT_MAX, 'USD'));
    }

    /** @return iterable<string, array{string}> */
    public static function withoutMinorUnit(): iterable
    {
        yield 'gold' => ['XAU'];
        yield 'bitcoin' => ['BTC'];
        yield 'unknown' => ['UNKNOWN_CURRENCY'];
        yield 'lower case' => ['cad'];
    }

    #[DataProvider('withoutMinorUnit')]
    public function testACodeWithoutAMinorUnitIsRefused(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        Currency::minorUnits($code);
    }
}
