<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Shopify\EntityQueries;
use IdempotentImporter\Source\Shopify\QueryLibrary;
use IdempotentImporter\Source\Shopify\ShopifyAdapter;
use IdempotentImporter\Source\SourceError;
use PHPUnit\Framework\TestCase;

final class QueryLibraryTest extends TestCase
{
    public function testEveryShopifyKindExceptShopHasAListQuery(): void
    {
        foreach ((new ShopifyAdapter())->kinds() as $kind) {
            if ($kind !== Kind::Shop) {
                self::assertSame($kind, EntityQueries::for($kind)->kind);
            }
        }
    }

    public function testListOperationsAreGeneratedWithIncrementalOrdering(): void
    {
        $document = QueryLibrary::bundled()->document('ProductList');

        self::assertStringContainsString('products(first: $first, after: $after, query: $query, sortKey: UPDATED_AT)', $document);
        self::assertStringContainsString('fragment ProductFields on Product', $document);
        self::assertStringContainsString('fragment VariantFields on ProductVariant', $document);
        self::assertSame(1, substr_count($document, 'fragment PageInfoFields on PageInfo'));
    }

    public function testUnsearchableSourcesTakeNoQueryVariable(): void
    {
        self::assertStringNotContainsString('$query', QueryLibrary::bundled()->document('DeliveryProfileList'));
    }

    public function testNestedPageOperationsReuseTheConnectionFragment(): void
    {
        $document = QueryLibrary::bundled()->document('OrderShippingLinesPage');

        self::assertStringContainsString('shippingLines(first: 250, after: $after, includeRemovals: false)', $document);
        self::assertStringContainsString('...ShippingLineConnectionFields', $document);
    }

    public function testIncrementalSources(): void
    {
        self::assertTrue(EntityQueries::for(Kind::Order)->isIncremental());
        self::assertFalse(EntityQueries::for(Kind::Menu)->isIncremental());
        self::assertFalse(EntityQueries::for(Kind::DeliveryProfile)->isIncremental());
    }

    public function testUnknownOperation(): void
    {
        $this->expectException(SourceError::class);
        QueryLibrary::bundled()->document('Nope');
    }
}
