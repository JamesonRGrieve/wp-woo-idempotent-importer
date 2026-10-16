<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Tests\Unit;

use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Shopify\QueryLibrary;
use IdempotentShopifyImporter\Shopify\ShopifyError;
use IdempotentShopifyImporter\Shopify\SourceRegistry;
use PHPUnit\Framework\TestCase;

final class QueryLibraryTest extends TestCase
{
    public function testEverySyncedKindExceptShopHasASource(): void
    {
        foreach (Kind::SYNC_ORDER as $kind) {
            if ($kind !== Kind::Shop) {
                self::assertSame($kind, SourceRegistry::for($kind)->kind);
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
        self::assertTrue(SourceRegistry::for(Kind::Order)->isIncremental());
        self::assertFalse(SourceRegistry::for(Kind::Menu)->isIncremental());
        self::assertFalse(SourceRegistry::for(Kind::DeliveryProfile)->isIncremental());
    }

    public function testUnknownOperation(): void
    {
        $this->expectException(ShopifyError::class);
        QueryLibrary::bundled()->document('Nope');
    }
}
