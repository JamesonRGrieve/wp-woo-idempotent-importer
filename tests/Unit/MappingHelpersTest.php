<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Tests\Unit;

use IdempotentShopifyImporter\Mapping\HtmlImages;
use IdempotentShopifyImporter\Mapping\LegacyPaths;
use IdempotentShopifyImporter\Mapping\ProductMapper;
use IdempotentShopifyImporter\Mapping\Values;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Sync\Outcome;
use IdempotentShopifyImporter\Sync\RunCounts;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MappingHelpersTest extends TestCase
{
    public function testFindsOnlyShopifyHostedImages(): void
    {
        $html = '<img src="https://cdn.shopify.com/s/files/a.png?v=1"><img src="//shop.example/cdn/shop/files/b.jpg">'
            . '<img src="https://other.example/c.png"><img alt="x" src=\'https://cdn.shopify.com/s/files/a.png?v=1\'>';

        self::assertSame(
            ['https://cdn.shopify.com/s/files/a.png?v=1', 'https://shop.example/cdn/shop/files/b.jpg'],
            HtmlImages::shopifyUrls($html),
        );
    }

    public function testReplacesImagesAndEscapes(): void
    {
        $html = '<p><img src="//shop.example/cdn/shop/files/b.jpg" alt="b"></p>';
        $replaced = HtmlImages::replace($html, ['https://shop.example/cdn/shop/files/b.jpg' => 'https://wp.example/b.jpg?x=1&y=2']);

        self::assertSame('<p><img src="https://wp.example/b.jpg?x=1&amp;y=2" alt="b"></p>', $replaced);
        self::assertSame('https://cdn.shopify.com/a.png', HtmlImages::identity('//cdn.shopify.com/a.png?v=9'));
    }

    public function testLegacyPaths(): void
    {
        self::assertSame('/products/silk-scarf', LegacyPaths::normalise('/collections/sale/products/Silk-Scarf/?variant=1'));
        self::assertSame('/pages/about', LegacyPaths::normalise('/pages/about/'));
        self::assertSame('/', LegacyPaths::normalise('/'));
        self::assertSame('/policies/refund-policy', LegacyPaths::policy('REFUND_POLICY'));
        self::assertSame('/blogs/news/fall', LegacyPaths::article('news', 'fall'));
    }

    public function testCompareAtPriceRule(): void
    {
        self::assertSame(['80.00', '60.00'], ProductMapper::prices('60.00', '80.00'));
        self::assertSame(['72.00', null], ProductMapper::prices('72.00', '72.00'));
        self::assertSame(['72.00', null], ProductMapper::prices('72.00', null));
        self::assertSame(['72.00', null], ProductMapper::prices('72.00', '0'));
        self::assertSame(['72.00', null], ProductMapper::prices('72.00', '50'));
    }

    public function testRunCounts(): void
    {
        $counts = new RunCounts();
        $counts->add(Kind::Product, Outcome::Created);
        $counts->add(Kind::Product, Outcome::Skipped);
        $counts->add(Kind::Order, Outcome::Deferred);

        self::assertSame(1, $counts->writes());
        self::assertTrue($counts->isIncomplete(Kind::Order));
        self::assertFalse($counts->isIncomplete(Kind::Product));
        self::assertSame("product: created 1, skipped 1\norder: deferred 1", $counts->summary());
        self::assertEquals($counts->toArray(), RunCounts::fromArray($counts->toArray())->toArray());
        self::assertSame('nothing to do', (new RunCounts())->summary());
    }

    public function testValuesAreTyped(): void
    {
        $values = new Values(['n' => 3, 's' => 'x', 'list' => [['a' => 'b']], 'meta' => ['ns.k' => ['type' => 't', 'value' => 'v']]]);

        self::assertSame('3', $values->string('n'));
        self::assertSame('b', $values->records('list')[0]->string('a'));
        self::assertSame(['ns.k' => ['type' => 't', 'value' => 'v']], $values->metafields());
        self::assertSame(['title' => null, 'description' => null], $values->seo());
        $this->expectException(InvalidArgumentException::class);
        $values->bool('s');
    }
}
