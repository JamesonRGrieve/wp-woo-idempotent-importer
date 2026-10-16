<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Source\Shopify\PageSizes;
use IdempotentImporter\Source\Shopify\QueryLibrary;
use IdempotentImporter\Source\SourceError;
use PHPUnit\Framework\TestCase;

final class PageSizesTest extends TestCase
{
    public function testStartsFromTheLibrarysDefaults(): void
    {
        $sizes = new PageSizes(QueryLibrary::bundled());

        self::assertSame(QueryLibrary::bundled()->pageSizes('OrderList'), $sizes->for('OrderList'));
        self::assertSame([], $sizes->for('Shop'));
    }

    public function testARefusalShrinksEverySizeInProportionAndOnlyThatOperation(): void
    {
        $sizes = new PageSizes(QueryLibrary::bundled());

        $sizes->refused('OrderList', 1800, 1000);

        self::assertSame(
            ['first' => 25, 'lineItemsFirst' => 125, 'shippingLinesFirst' => 125, 'eventsFirst' => 125, 'metafieldsFirst' => 125],
            $sizes->for('OrderList'),
            'scaled by 900 / 1800',
        );
        self::assertSame(QueryLibrary::bundled()->pageSizes('ProductList'), $sizes->for('ProductList'));
    }

    public function testARefusalBarelyOverTheLimitStillShrinksBelowTheMargin(): void
    {
        $sizes = new PageSizes(QueryLibrary::bundled());

        $sizes->refused('DeliveryProfileList', 1001, 1000);

        self::assertSame(['first' => 44], $sizes->for('DeliveryProfileList'), '50 × 900 / 1001');
    }

    public function testSizesNeverGoBelowOne(): void
    {
        $sizes = new PageSizes(QueryLibrary::bundled());

        $sizes->refused('ProductList', 1_000_000, 1000);

        self::assertSame(['first' => 1, 'variantsFirst' => 1, 'mediaFirst' => 1, 'collectionsFirst' => 1, 'metafieldsFirst' => 1], $sizes->for('ProductList'));
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('ProductList: one object costs 1500 points even one at a time');
        $sizes->refused('ProductList', 1500, 1000);
    }

    public function testAnOperationWithoutPageSizesCannotShrink(): void
    {
        $this->expectException(SourceError::class);
        (new PageSizes(QueryLibrary::bundled()))->refused('Shop', 1200, 1000);
    }

    public function testGrowsBackTowardTheDefaultsButNeverToARefusedSize(): void
    {
        $sizes = new PageSizes(QueryLibrary::bundled());
        $sizes->refused('DeliveryProfileList', 1800, 1000);
        self::assertSame(['first' => 25], $sizes->for('DeliveryProfileList'));

        $sizes->succeeded('DeliveryProfileList', 300);
        self::assertSame(['first' => 49], $sizes->for('DeliveryProfileList'), 'room to grow, capped just under the refused 50');

        $sizes->succeeded('DeliveryProfileList', 300);
        self::assertSame(['first' => 49], $sizes->for('DeliveryProfileList'), 'growing further would ask for the refused size');
    }

    public function testDoesNotGrowWithoutRoomOrWhenAtTheDefaults(): void
    {
        $sizes = new PageSizes(QueryLibrary::bundled());
        $sizes->succeeded('DeliveryProfileList', 10);
        self::assertSame(['first' => 50], $sizes->for('DeliveryProfileList'), 'never above the default');

        $sizes->refused('DeliveryProfileList', 1800, 1000);
        $sizes->succeeded('DeliveryProfileList', 950);
        self::assertSame(['first' => 25], $sizes->for('DeliveryProfileList'), 'no room under the 900 target');
    }

    public function testTheSameResponsesGiveTheSameSizes(): void
    {
        $run = static function (): array {
            $sizes = new PageSizes(QueryLibrary::bundled());
            $sizes->refused('ProductList', 1941, 1000);
            $sizes->succeeded('ProductList', 700);
            $sizes->refused('ProductList', 1027, 1000);
            $sizes->succeeded('ProductList', 822);

            return $sizes->for('ProductList');
        };

        self::assertSame($run(), $run());
    }

    public function testAStoreWithALowerLimitIsTargetedBelowIt(): void
    {
        $sizes = new PageSizes(QueryLibrary::bundled());
        $sizes->refused('DeliveryProfileList', 1000, 500);
        self::assertSame(['first' => 22], $sizes->for('DeliveryProfileList'), '50 × 450 / 1000');

        $sizes->succeeded('DeliveryProfileList', 449);
        self::assertSame(['first' => 22], $sizes->for('DeliveryProfileList'), 'growth aims under the reported limit, not 1000');
    }
}
