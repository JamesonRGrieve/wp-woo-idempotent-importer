<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\BigCommerce\BigCommerceAuthorizer;
use IdempotentImporter\Source\BigCommerce\BigCommerceReader;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class BigCommerceReaderTest extends TestCase
{
    private const API_BASE = 'https://api.bigcommerce.test';
    private const STORE_HASH = 'bcfix123';
    private const TOKEN = 'fixture-access-token';
    private const STORE = self::API_BASE . '/stores/' . self::STORE_HASH;

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
    }

    public function testReadsTheStoreWithTheAccountToken(): void
    {
        $transport = self::transport('v1');

        $store = $this->reader($transport)->shop();

        self::assertSame('gid://bigcommerce/Store/bcfix123', $store->id());
        self::assertSame('Northern Trail Outfitters', $store->string('name'));
        self::assertSame([self::STORE . '/v2/store'], array_column($transport->requests, 'url'));
        self::assertSame(self::TOKEN, $transport->requests[0]['headers'][BigCommerceAuthorizer::TOKEN_HEADER]);
        self::assertSame('application/json', $transport->requests[0]['headers']['Accept']);
    }

    public function testProductsArriveWithTheirVariantsBrandAndTheStoresWeightUnit(): void
    {
        $transport = self::transport('v1');

        $products = $this->all($this->reader($transport), Kind::Product);

        self::assertSame(
            ['gid://bigcommerce/Product/111', 'gid://bigcommerce/Product/112', 'gid://bigcommerce/Product/113'],
            self::gids($products),
        );
        $toque = $products[1];
        self::assertSame([1121, 1122], array_map(static fn(Node $variant): int => $variant->int('id'), $toque->list('variants')));
        self::assertSame('Northern Trail', $toque->string('brand'));
        self::assertSame('KGS', $toque->string('weight_units'));
        self::assertNull($products[2]->optionalString('brand'), 'a product without a brand');
        self::assertSame([
            self::STORE . '/v3/catalog/products?include=images%2Ccustom_fields&sort=id&direction=asc&page=1&limit=' . BigCommerceReader::PRODUCT_PAGE_SIZE,
            self::STORE . '/v3/catalog/brands?id%3Ain=5&page=1&limit=250',
            self::STORE . '/v2/store',
            self::STORE . '/v3/catalog/products/111/variants?page=1&limit=250',
            self::STORE . '/v3/catalog/products/112/variants?page=1&limit=250',
            self::STORE . '/v3/catalog/products/113/variants?page=1&limit=250',
        ], array_column($transport->requests, 'url'), 'one brand read per page, the store once, each product\'s variants');
    }

    public function testV3ListsArePagedByPageNumberUntilTheLastPage(): void
    {
        $transport = self::transport('v2');

        $customers = $this->all($this->reader($transport), Kind::Customer);

        self::assertSame(['gid://bigcommerce/Customer/41', 'gid://bigcommerce/Customer/42', 'gid://bigcommerce/Customer/43'], self::gids($customers));
        self::assertStringContainsString('/v3/customers?include=addresses%2Cstorecredit%2Cattributes%2Cformfields&sort=date_created%3Aasc&page=1&limit=250', $transport->requests[0]['url']);
        self::assertStringContainsString('page=2', $transport->requests[1]['url']);
        self::assertCount(2, $transport->requests, 'meta.pagination says there are two pages');
    }

    public function testDiscountsAreCouponsThenPromotionsWithTheirCodes(): void
    {
        $transport = self::transport('v1');

        $discounts = $this->all($this->reader($transport), Kind::Discount);

        self::assertSame([
            'gid://bigcommerce/Coupon/61', 'gid://bigcommerce/Coupon/62', 'gid://bigcommerce/Coupon/63', 'gid://bigcommerce/Coupon/64',
            'gid://bigcommerce/Promotion/71', 'gid://bigcommerce/Promotion/72',
        ], self::gids($discounts));
        self::assertSame(['BC-FALL15'], array_map(static fn(Node $code): string => $code->string('code'), $discounts[4]->list('codes')));
        self::assertSame([], $discounts[5]->list('codes'), 'an automatic promotion has no codes to read');
        self::assertStringContainsString('/v2/coupons?exclude_type=promotion&page=1', $transport->requests[0]['url'], 'promotions listed as coupons are read as promotions');
        self::assertSame([
            '/stores/bcfix123/v2/coupons',
            '/stores/bcfix123/v3/promotions',
            '/stores/bcfix123/v3/promotions/71/codes',
        ], array_map(static fn(array $request): string => (string) parse_url($request['url'], PHP_URL_PATH), $transport->requests));
    }

    public function testOrdersArriveWithTheirLinesShippingTaxesShipmentsAndRefunds(): void
    {
        $transport = self::transport('v2');

        $orders = $this->all($this->reader($transport), Kind::Order);

        self::assertSame(['gid://bigcommerce/Order/501', 'gid://bigcommerce/Order/502', 'gid://bigcommerce/Order/503'], self::gids($orders));
        $first = $orders[0];
        self::assertCount(2, $first->list('products'));
        self::assertCount(1, $first->list('shipping_addresses'));
        self::assertCount(3, $first->list('taxes'));
        self::assertSame('BC-WELCOME', $first->list('coupons')[0]->string('code'));
        self::assertSame('CP123456789CA', $first->list('shipments')[0]->string('tracking_number'));
        self::assertSame([9001], array_map(static fn(Node $refund): int => $refund->int('id'), $first->list('refunds')));
        self::assertSame([], $orders[1]->list('refunds'));
        $urls = array_column($transport->requests, 'url');
        self::assertStringContainsString('/v2/orders?include=fees&sort=id%3Aasc&page=1&limit=250', $urls[0]);
        self::assertSame(self::STORE . '/v3/orders/payment_actions/refunds?order_id%3Ain=501%2C502%2C503&page=1&limit=250', $urls[1], 'one refunds read for the page');
        self::assertContains(self::STORE . '/v2/orders/501/taxes?details=true&page=1&limit=250', $urls, 'taxes per line');
        self::assertCount(2 + 3 * count(BigCommerceReader::ORDER_PARTS), $urls);
    }

    public function testAnEmptyV2ListIsNoContent(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('bigcommerce', 'v1'), [new HttpResponse(204, '')]);

        $page = $this->reader($transport)->page(Kind::Order, null, null, '');

        self::assertSame([], $page->nodes);
        self::assertFalse($page->hasNextPage, 'a v2 list ends at a short page');
    }

    public function testIncrementalRunsAskForWhatChangedWhereBigCommerceCanFilter(): void
    {
        $transport = self::transport('v2');
        $reader = $this->reader($transport);
        $since = new DateTimeImmutable('2026-09-05T06:00:00-06:00');

        $reader->page(Kind::Product, null, $since, '');
        $reader->page(Kind::Customer, null, $since, '');
        $reader->page(Kind::Order, null, $since, '');

        $urls = array_column($transport->requests, 'url');
        self::assertStringContainsString('date_modified%3Amin=2026-09-05T12%3A00%3A00%2B00%3A00', $urls[0]);
        self::assertNotEmpty(array_filter($urls, static fn(string $url): bool => str_contains($url, '/v3/customers?') && str_contains($url, 'date_modified%3Amin=2026-09-05T12%3A00%3A00%2B00%3A00')));
        self::assertNotEmpty(array_filter($urls, static fn(string $url): bool => str_contains($url, '/v2/orders?') && str_contains($url, 'min_date_modified=2026-09-05T12%3A00%3A00%2B00%3A00')));
        foreach (Kind::SYNC_ORDER as $kind) {
            self::assertSame(in_array($kind, [Kind::Product, Kind::Customer, Kind::Order], true), $reader->isIncremental($kind), $kind->value);
        }
    }

    public function testContentPagesLeaveTheBlogPageToTheBlog(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $pages = $this->all($reader, Kind::Page);
        $blog = $reader->page(Kind::Blog, null, null, '');

        self::assertSame(['gid://bigcommerce/Page/81', 'gid://bigcommerce/Page/82', 'gid://bigcommerce/Page/84'], self::gids($pages));
        self::assertSame('<p>Outfitting Canadian trails since 2019.</p>', $pages[0]->string('body'));
        self::assertSame(['gid://bigcommerce/Blog/bcfix123'], self::gids($blog->nodes), 'one blog per store');
        self::assertSame(83, $blog->nodes[0]->requiredChild('page')->int('id'));
        self::assertFalse($blog->hasNextPage);
    }

    public function testBlogPostsCarryTheirBlogAndTheStoreUrl(): void
    {
        $posts = $this->all($this->reader(self::transport('v2')), Kind::Article);

        self::assertSame(['gid://bigcommerce/Post/91', 'gid://bigcommerce/Post/92'], self::gids($posts));
        self::assertSame('bcfix123', $posts[0]->string('blog_id'));
        self::assertSame('http://bigcommerce-fake:8080', $posts[0]->string('store_url'));
    }

    public function testReviewsAreReadOnlyForProductsThatHaveSome(): void
    {
        $transport = self::transport('v1');

        $reviews = $this->all($this->reader($transport), Kind::Review);

        self::assertSame(['gid://bigcommerce/Review/301', 'gid://bigcommerce/Review/302'], self::gids($reviews));
        self::assertSame(112, $reviews[1]->int('product_id'));
        self::assertStringContainsString('/v3/catalog/products?include_fields=reviews_count&sort=id&direction=asc&page=1', $transport->requests[0]['url']);
        self::assertCount(3, $transport->requests, 'the review counts, then the reviews of the two reviewed products (not the unreviewed lantern)');
    }

    public function testRedirectsIncludeTheirResolvedTargets(): void
    {
        $transport = self::transport('v1');

        $redirects = $this->all($this->reader($transport), Kind::UrlRedirect);

        self::assertSame(['gid://bigcommerce/Redirect/1', 'gid://bigcommerce/Redirect/2', 'gid://bigcommerce/Redirect/3'], self::gids($redirects));
        self::assertStringContainsString('include=to_url', $transport->requests[0]['url']);
    }

    public function testOneObjectByItsIdentity(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $mug = $reader->node(Kind::Product, 'gid://bigcommerce/Product/111');

        self::assertSame('gid://bigcommerce/Product/111', $mug?->id());
        self::assertSame([1110], array_map(static fn(Node $variant): int => $variant->int('id'), $mug->list('variants')));
        self::assertNull($reader->node(Kind::Product, 'gid://bigcommerce/Product/999'), 'a product BigCommerce no longer has is null');
        self::assertNull($reader->node(Kind::Order, 'gid://bigcommerce/Order/999'));
    }

    public function testKindsBigCommerceCannotReadAreRefused(): void
    {
        $reader = $this->reader(self::transport('v1'));

        try {
            $reader->node(Kind::Review, 'gid://bigcommerce/Review/301');
            self::fail('a review is not read on its own');
        } catch (SourceError) {
            $this->expectException(SourceError::class);
            $reader->page(Kind::Menu, null, null, '');
        }
    }

    public function testAStoreWithoutAHashOrTokenSaysSo(): void
    {
        try {
            BigCommerceReader::connect(self::API_BASE, '', self::TOKEN, new ScriptedTransport(), $this->sleeper);
            self::fail('a store hash is required');
        } catch (SourceError $e) {
            self::assertStringContainsString('store hash', $e->getMessage());
        }
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('BigCommerce is not configured');
        BigCommerceReader::connect(self::API_BASE, self::STORE_HASH, '', new ScriptedTransport(), $this->sleeper);
    }

    public function testARejectedTokenIsNotRetried(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('bigcommerce', 'v1'), [new HttpResponse(401, '{}')]);

        try {
            $this->reader($transport)->shop();
            self::fail('a rejected token is an error');
        } catch (SourceError $e) {
            self::assertStringContainsString('rejected the credentials', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
        self::assertCount(1, $transport->requests, 'a static token has nothing to renew');
    }

    public function testRateLimitsWaitForTheWindowToReset(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('bigcommerce', 'v1'), [new HttpResponse(429, '{}', null, '2500')]);

        $this->reader($transport)->page(Kind::Collection, null, null, '');

        self::assertSame([2.5], $this->sleeper->waits, 'X-Rate-Limit-Time-Reset-Ms, in seconds');
    }

    /** @return list<Node> every node of a kind, following the cursor to the end */
    private function all(BigCommerceReader $reader, Kind $kind): array
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

    private static function transport(string $state): ScriptedTransport
    {
        return new ScriptedTransport(new FixtureResponder('bigcommerce', $state));
    }

    private function reader(ScriptedTransport $transport): BigCommerceReader
    {
        return BigCommerceReader::connect(self::API_BASE, self::STORE_HASH, self::TOKEN, $transport, $this->sleeper);
    }
}
