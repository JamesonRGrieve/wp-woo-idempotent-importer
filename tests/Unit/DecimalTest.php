<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Support\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function normalised(): iterable
    {
        yield 'trailing zeros' => ['10.50', '10.5'];
        yield 'whole with zeros' => ['10.00', '10'];
        yield 'ends in zero' => ['120', '120'];
        yield 'leading zeros' => ['007.10', '7.1'];
        yield 'fraction only' => ['.5', '0.5'];
        yield 'negative zero' => ['-0.0', '0'];
        yield 'negative' => ['-3.20', '-3.2'];
    }

    #[DataProvider('normalised')]
    public function testNormalise(string $input, string $expected): void
    {
        self::assertSame($expected, Decimal::normalise($input));
    }

    public function testShiftMovesThePointBothWays(): void
    {
        self::assertSame('15.5', Decimal::shift('0.155', 2));
        self::assertSame('15', Decimal::shift('0.15', 2));
        self::assertSame('1.55', Decimal::shift('155', -2));
        self::assertSame('0.05', Decimal::shift('5', -2));
        self::assertSame('-250', Decimal::shift('-2.5', 2));
    }

    public function testSumIsExact(): void
    {
        self::assertSame('0.3', Decimal::sum(['0.1', '0.2']));
        self::assertSame('6.56', Decimal::sum(['3.0', '3.06', '0.5']));
        self::assertSame('0', Decimal::sum([]));
        self::assertSame('-1.5', Decimal::sum(['1', '-2.5']));
    }

    public function testCompare(): void
    {
        self::assertSame(1, Decimal::compare('80.00', '60'));
        self::assertSame(0, Decimal::compare('72.00', '72'));
        self::assertSame(-1, Decimal::compare('9.99', '10'));
        self::assertSame(-1, Decimal::compare('-5', '1'));
        self::assertSame(1, Decimal::compare('-1', '-2'));
        self::assertSame(1, Decimal::compare('100', '99.999'));
    }

    public function testIsZero(): void
    {
        self::assertTrue(Decimal::isZero(null));
        self::assertTrue(Decimal::isZero('0.00'));
        self::assertFalse(Decimal::isZero('0.01'));
    }
}
