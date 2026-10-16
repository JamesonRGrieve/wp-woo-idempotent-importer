<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\RetryLater;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\Wix\Catalog;
use IdempotentImporter\Source\Wix\WixAuthorizer;
use IdempotentImporter\Source\Wix\WixReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class WixReaderTest extends TestCase
{
    private const API_BASE = 'https://www.wixapis.test';
    private const API_KEY = 'fixture-api-key';
    private const SITE_ID = 'fixture-site';
    private const MUG = 'gid://wix/Product/1a7e5c10-0001-4c2e-9b41-6f0d2a1e0001';
    private const TOWEL = 'gid://wix/Product/1a7e5c10-0002-4c2e-9b41-6f0d2a1e0002';
    private const BOWL = 'gid://wix/Product/1a7e5c10-0003-4c2e-9b41-6f0d2a1e0003';

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
    }

    public function testReadsTheSitePropertiesWithTheApiKeyAndSiteId(): void
    {
        $transport = self::transport('v1');

        $site = $this->reader($transport)->shop();

        self::assertSame('gid://wix/Site/fixture-site', $site->id());
        self::assertSame('Prairie Pottery Co.', $site->string('businessName'));
        self::assertSame([self::API_BASE . '/site-properties/v4/properties'], array_column($transport->requests, 'url'));
        self::assertSame(self::API_KEY, $transport->requests[0]['headers']['Authorization'], 'the key itself, no scheme');
        self::assertSame(self::SITE_ID, $transport->requests[0]['headers'][WixAuthorizer::SITE_HEADER]);
    }

    public function testV3ProductsArriveWithTheirVariantsAndInventoryReadForTheWholePage(): void
    {
        $transport = self::transport('v1');

        $products = $this->all($this->reader($transport), Kind::Product);

        self::assertSame([self::MUG, self::TOWEL, self::BOWL], self::gids($products));
        $towel = $products[1];
        self::assertSame(Catalog::V3->value, $towel->string('catalog'));
        self::assertSame(['WIX-TOWEL-SAGE', 'WIX-TOWEL-RUST'], array_map(static fn(Node $variant): string => $variant->string('sku'), $towel->list('variants')));
        self::assertCount(2, $towel->list('inventory_items'));
        self::assertSame(['/stores/v3/provision/version', '/stores/v3/products/query', '/stores/v3/products/query-variants', '/stores/v3/inventory-items/query'], self::paths($transport));
        self::assertSame([
            'query' => ['sort' => [['fieldName' => 'createdDate', 'order' => 'ASC']], 'cursorPaging' => ['limit' => WixReader::PAGE_SIZE]],
            'fields' => WixReader::PRODUCT_FIELDS,
        ], self::body($transport, 1));
        self::assertSame(['productData.productId' => ['$in' => [
            '1a7e5c10-0001-4c2e-9b41-6f0d2a1e0001', '1a7e5c10-0002-4c2e-9b41-6f0d2a1e0002', '1a7e5c10-0003-4c2e-9b41-6f0d2a1e0003',
        ]]], self::filter(self::body($transport, 2)), 'one variants read for the page');
    }

    public function testCursorPagesSendOnlyTheCursorAfterTheFirst(): void
    {
        $transport = self::transport('v2');

        $contacts = $this->all($this->reader($transport), Kind::Customer);

        self::assertSame([
            'gid://wix/Contact/2c4e6a80-0001-4b3d-9e1f-5a6b7c8d0001',
            'gid://wix/Contact/2c4e6a80-0002-4b3d-9e1f-5a6b7c8d0002',
            'gid://wix/Contact/2c4e6a80-0003-4b3d-9e1f-5a6b7c8d0003',
        ], self::gids($contacts));
        self::assertSame(['query' => ['cursorPaging' => ['limit' => WixReader::PAGE_SIZE, 'cursor' => 'contacts-page-2']]], self::body($transport, 1), 'no filter or sort beside a cursor');
    }

    public function testIncrementalRunsFilterByUpdateTimeAndRereadProductsWhoseStockChanged(): void
    {
        $transport = self::transport('v1');
        $reader = $this->reader($transport);
        $since = new DateTimeImmutable('2026-09-05T06:00:00-06:00');

        $first = $reader->page(Kind::Product, null, $since, '');
        self::assertTrue($first->hasNextPage, 'the stock phase follows the changed products');
        self::assertSame('stock:', $first->endCursor);
        self::assertSame(['updatedDate' => ['$gt' => '2026-09-05T12:00:00.000Z']], self::filter(self::body($transport, 1)));
        $stock = $reader->page(Kind::Product, $first->endCursor, $since, '');

        self::assertSame([self::MUG], self::gids($stock->nodes), 'the product whose inventory changed');
        self::assertFalse($stock->hasNextPage);
        $bodies = array_map(static fn(int $index): array => self::body($transport, $index), array_keys($transport->requests));
        self::assertContains(['query' => [
            'sort' => [['fieldName' => 'updatedDate', 'order' => 'ASC']],
            'filter' => ['updatedDate' => ['$gt' => '2026-09-05T12:00:00.000Z']],
            'cursorPaging' => ['limit' => WixReader::PAGE_SIZE],
        ]], $bodies);
        foreach ([Kind::Customer, Kind::Order, Kind::Collection, Kind::Product] as $kind) {
            self::assertTrue($reader->isIncremental($kind), $kind->value);
        }
        foreach ([Kind::Discount, Kind::Blog, Kind::Article, Kind::UrlRedirect, Kind::Review] as $kind) {
            self::assertFalse($reader->isIncremental($kind), $kind->value);
        }
    }

    public function testAV1CatalogIsReadFromTheV1ApisAndIsNotIncremental(): void
    {
        $transport = self::transport('catalog-v1');
        $reader = $this->reader($transport);

        $products = $this->all($reader, Kind::Product);
        $collections = $this->all($reader, Kind::Collection);

        self::assertSame(Catalog::V1, $reader->catalog());
        self::assertSame(['gid://wix/Product/6b5a4c30-1001-4d2e-8f1a-9b0c1d2e1001', 'gid://wix/Product/6b5a4c30-1002-4d2e-8f1a-9b0c1d2e1002'], self::gids($products));
        self::assertSame(Catalog::V1->value, $products[0]->string('catalog'));
        self::assertSame(['gid://wix/Collection/00000000-000000-000000-000000000001', 'gid://wix/Collection/c01e0000-1001-4a5b-8c9d-0e1f2a3b1001'], self::gids($collections));
        self::assertSame(['query' => ['paging' => ['limit' => WixReader::PAGE_SIZE, 'offset' => 0]], ...WixReader::V1_PRODUCT_OPTIONS], self::body($transport, 1));
        self::assertFalse($reader->isIncremental(Kind::Product));
        self::assertFalse($reader->isIncremental(Kind::Collection));
        self::assertSame('gid://wix/Product/6b5a4c30-1001-4d2e-8f1a-9b0c1d2e1001', $reader->node(Kind::Product, 'gid://wix/Product/6b5a4c30-1001-4d2e-8f1a-9b0c1d2e1001')?->id());
    }

    public function testOrdersArriveWithTheirPaymentsRefundsAndFulfillments(): void
    {
        $transport = self::transport('v2');

        $orders = $this->all($this->reader($transport), Kind::Order);

        self::assertSame([
            'gid://wix/Order/8f1e2d30-0001-4c5b-a69d-7e8f9a0b0001',
            'gid://wix/Order/8f1e2d30-0002-4c5b-a69d-7e8f9a0b0002',
            'gid://wix/Order/8f1e2d30-0003-4c5b-a69d-7e8f9a0b0003',
        ], self::gids($orders));
        self::assertCount(1, $orders[0]->list('payments'));
        self::assertCount(2, $orders[0]->list('refunds'));
        self::assertCount(1, $orders[0]->list('fulfillments'));
        self::assertSame([], $orders[1]->list('payments'));
        self::assertSame(['/ecom/v1/orders/search', '/ecom/v1/payments/list-by-ids', '/ecom/v1/fulfillments/list-by-ids'], self::paths($transport));
        self::assertSame(['search' => ['sort' => [['fieldName' => 'createdDate', 'order' => 'ASC']], 'cursorPaging' => ['limit' => WixReader::PAGE_SIZE]]], self::body($transport, 0));
    }

    public function testTheBlogComesFirstThenItsCategoriesAndPostsCarryTheSite(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $blogs = $reader->page(Kind::Blog, null, null, '');
        $posts = $this->all($reader, Kind::Article);

        self::assertSame(['gid://wix/Blog/fixture-site', 'gid://wix/BlogCategory/4d3c2b10-0001-4e5f-8a9b-0c1d2e3f0001'], self::gids($blogs->nodes));
        self::assertFalse($blogs->hasNextPage, 'a short page ends the offset paging');
        self::assertSame('fixture-site', $posts[0]->string('blog_id'));
    }

    public function testRedirectsCouponsAndReviews(): void
    {
        $transport = self::transport('v1');
        $reader = $this->reader($transport);

        self::assertCount(3, $reader->page(Kind::UrlRedirect, null, null, '')->nodes);
        $coupons = $this->all($reader, Kind::Discount);
        self::assertCount(5, $coupons);
        self::assertSame(Catalog::V3->value, $coupons[0]->string('catalog'), 'coupons name the catalog their scope targets');
        $reviews = $this->all($reader, Kind::Review);
        self::assertCount(2, $reviews);
        $reviewBody = self::body($transport, count($transport->requests) - 1);
        self::assertSame(['namespace' => WixReader::REVIEWS_NAMESPACE], self::filter($reviewBody));
        self::assertTrue($reviewBody['returnPrivateReviews'] ?? null);
    }

    public function testOneObjectByItsIdentity(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $mug = $reader->node(Kind::Product, self::MUG);
        self::assertSame(self::MUG, $mug?->id());
        self::assertCount(1, $mug->list('variants'));
        self::assertSame('gid://wix/Order/8f1e2d30-0001-4c5b-a69d-7e8f9a0b0001', $reader->node(Kind::Order, 'gid://wix/Order/8f1e2d30-0001-4c5b-a69d-7e8f9a0b0001')?->id());
        self::assertSame('gid://wix/Contact/2c4e6a80-0001-4b3d-9e1f-5a6b7c8d0001', $reader->node(Kind::Customer, 'gid://wix/Contact/2c4e6a80-0001-4b3d-9e1f-5a6b7c8d0001')?->id());
        self::assertNull($reader->node(Kind::Product, 'gid://wix/Product/9f9f9f9f-0000-4000-8000-00000000dead'), 'a product Wix no longer has is null');
    }

    public function testKindsWixCannotReadAreRefused(): void
    {
        $reader = $this->reader(self::transport('v1'));

        try {
            $reader->node(Kind::Review, 'gid://wix/Review/x');
            self::fail('a review is not read on its own');
        } catch (SourceError) {
            $this->expectException(SourceError::class);
            $reader->page(Kind::Menu, null, null, '');
        }
    }

    public function testASiteWithoutAKeyOrSiteIdSaysSo(): void
    {
        try {
            WixReader::connect(self::API_BASE, '', self::SITE_ID, new ScriptedTransport(), $this->sleeper);
            self::fail('an API key is required');
        } catch (SourceError $e) {
            self::assertStringContainsString('API key', $e->getMessage());
        }
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('enter the site id');
        WixReader::connect(self::API_BASE, self::API_KEY, '', new ScriptedTransport(), $this->sleeper);
    }

    public function testARejectedKeyIsNotRetriedAndNeverNamed(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('wix', 'v1'), [new HttpResponse(401, '{}')]);

        try {
            $this->reader($transport)->shop();
            self::fail('a rejected key is an error');
        } catch (SourceError $e) {
            self::assertStringContainsString('rejected the credentials', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
        self::assertCount(1, $transport->requests, 'a static key has nothing to renew');
    }

    public function testARateLimitWithoutAHintPausesForTheMinuteWixAsksFor(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('wix', 'v1'), [new HttpResponse(429, '{}')]);

        try {
            $this->reader($transport)->shop();
            self::fail('a minute is too long to wait inline');
        } catch (RetryLater $e) {
            self::assertSame(WixReader::RATE_LIMIT_COOLDOWN_SECONDS, $e->seconds);
        }
        self::assertSame([], $this->sleeper->waits);
    }

    /** @return list<Node> every node of a kind, following the cursor to the end */
    private function all(WixReader $reader, Kind $kind): array
    {
        $nodes = [];
        $cursor = null;
        do {
            $page = $reader->page($kind, $cursor, null, '');
            $nodes = [...$nodes, ...$page->nodes];
            $cursor = $page->endCursor;
        } while ($page->hasNextPage);

        return $nodes;
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<string>
     */
    private static function gids(array $nodes): array
    {
        return array_map(static fn(Node $node): string => $node->id(), $nodes);
    }

    /** @return list<string> */
    private static function paths(ScriptedTransport $transport): array
    {
        return array_map(static fn(array $request): string => (string) parse_url($request['url'], PHP_URL_PATH), $transport->requests);
    }

    /** @return array<array-key, mixed> a request's JSON body (none for a GET) */
    private static function body(ScriptedTransport $transport, int $index): array
    {
        $sent = $transport->requests[$index]['body'];
        $body = $sent === '' ? [] : json_decode($sent, true, 512, JSON_THROW_ON_ERROR);

        return is_array($body) ? $body : [];
    }

    /** @param array<array-key, mixed> $body the filter of a request's `query`, if it has one */
    private static function filter(array $body): mixed
    {
        $query = $body['query'] ?? null;

        return is_array($query) ? $query['filter'] ?? null : null;
    }

    private static function transport(string $state): ScriptedTransport
    {
        return new ScriptedTransport(new FixtureResponder('wix', $state));
    }

    private function reader(ScriptedTransport $transport): WixReader
    {
        return WixReader::connect(self::API_BASE, self::API_KEY, self::SITE_ID, $transport, $this->sleeper);
    }
}
