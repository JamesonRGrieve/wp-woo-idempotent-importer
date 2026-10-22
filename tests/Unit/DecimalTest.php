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

    public function testMultiplyByAnIntegerIsExact(): void
    {
        self::assertSame('59.97', Decimal::multiply('19.99', 3));
        self::assertSame('0.3', Decimal::multiply('0.1', 3));
        self::assertSame('64', Decimal::multiply('32', 2));
        self::assertSame('0', Decimal::multiply('12.5', 0));
        self::assertSame('-2.5', Decimal::multiply('1.25', -2));
    }

    public function testSumIsExact(): void
    {
        self::assertSame('0.3', Decimal::sum(['0.1', '0.2']));
        self::assertSame('6.56', Decimal::sum(['3.0', '3.06', '0.5']));
        self::assertSame('0', Decimal::sum([]));
        self::assertSame('-1.5', Decimal::sum(['1', '-2.5']));
    }

    public function testSubtractIsExact(): void
    {
        self::assertSame('7.5', Decimal::subtract('10', '2.5'));
        self::assertSame('0.1', Decimal::subtract('0.3', '0.2'));
        self::assertSame('12.5', Decimal::subtract('10', '-2.5'));
        self::assertSame('-1', Decimal::subtract('1.5', '2.50'));
        self::assertSame('5', Decimal::subtract('5', '0'));
    }

    public function testProductIsExact(): void
    {
        self::assertSame('1.625', Decimal::product('12.5', '0.13'));
        self::assertSame('-0.06', Decimal::product('-0.2', '0.3'));
        self::assertSame('0', Decimal::product('19.99', '0'));
        self::assertSame('300', Decimal::product('15', '20'));
    }

    public function testRoundHalvesAwayFromZero(): void
    {
        self::assertSame('1.63', Decimal::round('1.625', 2));
        self::assertSame('1.62', Decimal::round('1.6249', 2));
        self::assertSame('-0.01', Decimal::round('-0.005', 2));
        self::assertSame('0', Decimal::round('-0.004', 2));
        self::assertSame('1', Decimal::round('0.995', 2));
        self::assertSame('2.5', Decimal::round('2.5', 2), 'already within the places');
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

    /** @return iterable<string, array{int|float|string, string}> */
    public static function numbers(): iterable
    {
        yield 'a JSON float' => [19.99, '19.99'];
        yield 'a float with no exact binary form' => [0.1, '0.1'];
        yield 'a sum that is not 0.3 in binary' => [0.1 + 0.2, '0.30000000000000004'];
        yield 'an exponent float' => [1e2, '100'];
        yield 'a large float' => [1.5e25, '15000000000000000000000000'];
        yield 'a small float' => [2.5e-7, '0.00000025'];
        yield 'a negative float' => [-12.5, '-12.5'];
        yield 'an integer' => [42, '42'];
        yield 'an exponent string' => ['1e2', '100'];
        yield 'a negative exponent string' => ['1.5E-3', '0.0015'];
        yield 'a money string' => ['225.0000', '225'];
        yield 'a padded string' => [' 7.10 ', '7.1'];
    }

    #[DataProvider('numbers')]
    public function testANumberIsReadExactlyAsSpelled(int|float|string $number, string $expected): void
    {
        self::assertSame($expected, Decimal::of($number));
    }

    public function testANonNumberIsNotADecimal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::of('12,50');
    }

    public function testInfinityIsNotADecimal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::of(INF);
    }
}
