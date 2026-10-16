<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\HtmlImages;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Mapping\VariantAttributes;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Shopify\Mapping\ProductMapper;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Sync\Outcome;
use IdempotentImporter\Sync\RunCounts;
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
        self::assertSame('/shop/p/trail-mug', LegacyPaths::pathOf('https://trail.example/shop/p/Trail-Mug/'));
        self::assertSame('/kitchen', LegacyPaths::pathOf('/kitchen/'));
        self::assertNull(LegacyPaths::pathOf('https://trail.example/'), 'the home page has nothing to redirect');
        self::assertNull(LegacyPaths::pathOf(' '));
        self::assertNull(LegacyPaths::pathOf(null));
    }

    public function testTimesAreIsoUtc(): void
    {
        $node = new Node(['iso' => '2026-09-01T10:00:00.187Z', 'offset' => '2026-09-01T10:00:00-06:00', 'rfc' => 'Tue, 01 Sep 2026 10:00:00 -0600', 'empty' => '', 'bad' => 'soon']);

        self::assertSame('2026-09-01T10:00:00Z', Times::iso($node, 'iso'), 'fractions of a second dropped');
        self::assertSame('2026-09-01T16:00:00Z', Times::iso($node, 'offset'));
        self::assertSame('2026-09-01T16:00:00Z', Times::requiredIso($node, 'rfc'), 'RFC 2822');
        self::assertNull(Times::iso($node, 'empty'));
        self::assertNull(Times::iso($node, 'missing'));
        try {
            Times::requiredIso($node, 'missing');
            self::fail('a required time must be there');
        } catch (SourceError $e) {
            self::assertSame('missing is missing.', $e->getMessage());
        }
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('bad is not a timestamp: soon');
        Times::iso($node, 'bad');
    }

    public function testVariantAttributesInFirstUseOrder(): void
    {
        $variants = [
            ['options' => ['Size' => 'S', 'Colour' => 'Red']],
            ['options' => ['Size' => 'M', 'Colour' => 'Red']],
            ['options' => ['Colour' => 'Navy', 'Fit' => 'Slim']],
            ['options' => []],
        ];

        self::assertSame([
            ['name' => 'Size', 'position' => 1, 'values' => ['S', 'M']],
            ['name' => 'Colour', 'position' => 2, 'values' => ['Red', 'Navy']],
            ['name' => 'Fit', 'position' => 3, 'values' => ['Slim']],
        ], VariantAttributes::fromVariants($variants));
        self::assertSame([], VariantAttributes::fromVariants([]));
    }

    public function testSlugsFromTitles(): void
    {
        self::assertSame('wool-toque-navy', Slugs::fromTitle('Wool Toque (Navy)'));
        self::assertSame('', Slugs::fromTitle('!!!'));
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
