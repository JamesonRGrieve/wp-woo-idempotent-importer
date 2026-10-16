<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Ecwid\EcwidAuthorizer;
use IdempotentImporter\Source\Ecwid\EcwidReader;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class EcwidReaderTest extends TestCase
{
    private const API_HOST = 'https://app.ecwid.test';
    private const STORE_ID = '7400100';
    private const TOKEN = 'secret_fixture-token';
    private const STORE = self::API_HOST . '/api/v3/' . self::STORE_ID;

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
    }

    public function testReadsTheStoreProfileWithTheSecretToken(): void
    {
        $transport = self::transport('v1');

        $shop = $this->reader($transport)->shop();

        self::assertSame('gid://ecwid/Shop/7400100', $shop->id());
        self::assertSame('Cedar & Wick Candle Co.', $shop->requiredChild('settings')->string('storeName'));
        self::assertSame([self::STORE . '/profile'], array_column($transport->requests, 'url'));
        self::assertSame('Bearer ' . self::TOKEN, $transport->requests[0]['headers'][EcwidAuthorizer::HEADER]);
        self::assertSame('application/json', $transport->requests[0]['headers']['Accept']);
    }

    public function testProductsArriveWithTheirVariationsAndTheStoresWeightUnit(): void
    {
        $transport = self::transport('v1');

        $products = $this->all($this->reader($transport), Kind::Product);

        self::assertSame(['gid://ecwid/Product/9201', 'gid://ecwid/Product/9202', 'gid://ecwid/Product/9203'], self::gids($products));
        self::assertSame([920201, 920202], array_map(static fn(Node $combination): int => $combination->int('id'), $products[1]->list('combinations')));
        self::assertSame('KILOGRAM', $products[0]->string('weight_unit'));
        self::assertSame(
            [self::STORE . '/products?cleanUrls=true&offset=0&limit=100', self::STORE . '/profile'],
            array_column($transport->requests, 'url'),
            'one page of products with their variations, and the profile once',
        );
    }

    public function testListsArePagedByOffsetUntilTheTotal(): void
    {
        $items = array_map(static fn(int $id): array => ['id' => $id, 'email' => "c{$id}@example.com"], range(1, EcwidReader::PAGE_SIZE));
        $full = ['total' => 102, 'count' => EcwidReader::PAGE_SIZE, 'offset' => 0, 'limit' => EcwidReader::PAGE_SIZE, 'items' => $items];
        $transport = new ScriptedTransport(new FixtureResponder('ecwid', 'v1'), [new HttpResponse(200, (string) json_encode($full))]);
        $reader = $this->reader($transport);

        $first = $reader->page(Kind::Customer, null, null, '');
        $second = $reader->page(Kind::Customer, $first->endCursor, null, '');

        self::assertCount(EcwidReader::PAGE_SIZE, $first->nodes);
        self::assertTrue($first->hasNextPage, 'fewer than the total so far');
        self::assertSame('100', $first->endCursor);
        self::assertSame(['gid://ecwid/Customer/9401', 'gid://ecwid/Customer/9402'], self::gids($second->nodes));
        self::assertFalse($second->hasNextPage, 'the total is reached');
        self::assertNull($second->endCursor);
        self::assertSame(self::STORE . '/customers?offset=100&limit=100', $transport->requests[1]['url']);
    }

    public function testIncrementalKindsAskForWhatChangedSinceAsAUnixTimestamp(): void
    {
        $transport = self::transport('v2');
        $reader = $this->reader($transport);
        $since = new DateTimeImmutable('2026-09-09T06:00:00-06:00');

        foreach ([Kind::Product, Kind::Customer, Kind::Discount, Kind::Order, Kind::Review, Kind::Collection, Kind::UrlRedirect] as $kind) {
            $reader->page($kind, null, $since, '');
        }

        $urls = array_column($transport->requests, 'url');
        $filter = 'updatedFrom=1788955200';
        foreach (['/products?', '/customers?', '/discount_coupons?', '/orders?', '/reviews?'] as $list) {
            self::assertNotEmpty(array_filter($urls, static fn(string $url): bool => str_contains($url, $list) && str_contains($url, $filter)), "{$list} asks from the last run");
        }
        foreach (['/categories?', '/instant-site/redirects?'] as $list) {
            self::assertNotEmpty(array_filter($urls, static fn(string $url): bool => str_contains($url, $list) && !str_contains($url, 'updatedFrom')), "{$list} is read in full");
        }
        foreach (Kind::SYNC_ORDER as $kind) {
            self::assertSame(in_array($kind, [Kind::Product, Kind::Customer, Kind::Discount, Kind::Order, Kind::Review], true), $reader->isIncremental($kind), $kind->value);
        }
    }

    public function testOrdersCarryTheStoresCurrencyAndRedirectsItsUrl(): void
    {
        $reader = $this->reader(self::transport('v2'));

        $orders = $this->all($reader, Kind::Order);
        $redirects = $this->all($reader, Kind::UrlRedirect);

        self::assertSame(['gid://ecwid/Order/CW-1001', 'gid://ecwid/Order/CW-1002', 'gid://ecwid/Order/CW-1003'], self::gids($orders));
        self::assertSame('CAD', $orders[0]->string('currency'));
        self::assertCount(1, $orders[0]->list('refunds'));
        self::assertSame(['gid://ecwid/Redirect/rd-41a', 'gid://ecwid/Redirect/rd-41b'], self::gids($redirects));
        self::assertSame('https://cedarwick.example', $redirects[0]->string('store_url'));
    }

    public function testThePlainKinds(): void
    {
        $transport = self::transport('v1');
        $reader = $this->reader($transport);

        self::assertSame(['gid://ecwid/Category/9101', 'gid://ecwid/Category/9102'], self::gids($this->all($reader, Kind::Collection)));
        self::assertSame(self::STORE . '/categories?hidden_categories=true&offset=0&limit=100', $transport->requests[0]['url'], 'disabled categories too');
        self::assertSame(
            ['gid://ecwid/DiscountCoupon/9501', 'gid://ecwid/DiscountCoupon/9502', 'gid://ecwid/DiscountCoupon/9503', 'gid://ecwid/DiscountCoupon/9504', 'gid://ecwid/DiscountCoupon/9505'],
            self::gids($this->all($reader, Kind::Discount)),
        );
        self::assertSame(['gid://ecwid/Review/9701', 'gid://ecwid/Review/9702'], self::gids($this->all($reader, Kind::Review)));
    }

    public function testOneObjectByItsIdentity(): void
    {
        $transport = self::transport('v1');
        $reader = $this->reader($transport);

        $melts = $reader->node(Kind::Product, 'gid://ecwid/Product/9202');

        self::assertSame('gid://ecwid/Product/9202', $melts?->id());
        self::assertCount(2, $melts->list('combinations'));
        self::assertSame('KILOGRAM', $melts->string('weight_unit'));
        self::assertSame(self::STORE . '/products/9202?cleanUrls=true', $transport->requests[0]['url']);
        self::assertSame('gid://ecwid/Order/CW-1001', $reader->node(Kind::Order, 'gid://ecwid/Order/CW-1001')?->id());
        self::assertSame('CAD', $reader->node(Kind::Order, 'gid://ecwid/Order/CW-1002')?->string('currency'));
        self::assertSame('gid://ecwid/Customer/9401', $reader->node(Kind::Customer, 'gid://ecwid/Customer/9401')?->id());
        self::assertSame('gid://ecwid/Category/9102', $reader->node(Kind::Collection, 'gid://ecwid/Category/9102')?->id());
        self::assertSame('gid://ecwid/Review/9702', $reader->node(Kind::Review, 'gid://ecwid/Review/9702')?->id());
        self::assertContains(self::STORE . '/reviews?reviewId=9702', array_column($transport->requests, 'url'), 'found by the reviews search');
        self::assertNull($reader->node(Kind::Product, 'gid://ecwid/Product/9999'), 'a product Ecwid no longer has is null');
        self::assertNull($reader->node(Kind::Order, 'gid://ecwid/Order/CW-9999'));
        self::assertNull($reader->node(Kind::Review, 'gid://ecwid/Review/9999'), 'a review the search does not find');
    }

    public function testKindsEcwidCannotReadAreRefused(): void
    {
        $reader = $this->reader(self::transport('v1'));
        $refused = [];
        foreach (['coupon' => [Kind::Discount, 'gid://ecwid/DiscountCoupon/9501'], 'redirect' => [Kind::UrlRedirect, 'gid://ecwid/Redirect/rd-41a'], 'bad id' => [Kind::Product, 'gid://ecwid/Product/abc']] as $case => [$kind, $gid]) {
            try {
                $reader->node($kind, $gid);
            } catch (SourceError) {
                $refused[] = $case;
            }
        }

        self::assertSame(['coupon', 'redirect', 'bad id'], $refused);
        $this->expectException(SourceError::class);
        $reader->page(Kind::Menu, null, null, '');
    }

    public function testAStoreWithoutAnIdOrTokenSaysSo(): void
    {
        try {
            EcwidReader::connect(self::API_HOST, 'my-store', self::TOKEN, new ScriptedTransport(), $this->sleeper);
            self::fail('a numeric store id is required');
        } catch (SourceError $e) {
            self::assertStringContainsString('store ID', $e->getMessage());
        }
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Ecwid is not configured: enter the secret access token of the custom app.');
        EcwidReader::connect(self::API_HOST, self::STORE_ID, '', new ScriptedTransport(), $this->sleeper);
    }

    public function testARejectedTokenIsNotRetried(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('ecwid', 'v1'), [new HttpResponse(403, '{"errorCode":"INVALID_API_TOKEN"}')]);

        try {
            $this->reader($transport)->shop();
            self::fail('a rejected token is an error');
        } catch (CredentialsRejected $e) {
            self::assertStringContainsString('Ecwid rejected the credentials', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
        self::assertCount(1, $transport->requests, 'a static token has nothing to renew, and Ecwid blocks a token that keeps failing');
    }

    public function testRequestsArePacedUnderTheMinuteLimit(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $reader->page(Kind::Collection, null, null, '');
        $reader->page(Kind::Collection, null, null, '');

        self::assertCount(1, $this->sleeper->waits);
        self::assertEqualsWithDelta(60 / 600, $this->sleeper->waits[0], 1e-6, '600 requests a minute: one every 0.1 seconds');
    }

    public function testATooManyRequestsAnswerWaitsOutItsRetryAfter(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('ecwid', 'v1'), [new HttpResponse(429, '{}', '3')]);

        $page = $this->reader($transport)->page(Kind::Collection, null, null, '');

        self::assertCount(2, $page->nodes);
        self::assertCount(2, $transport->requests);
        self::assertSame([3.0], $this->sleeper->waits, 'the Retry-After cool-down (longer than the pacing interval)');
    }

    /** @return list<Node> every node of a kind, following the cursor to the end */
    private function all(EcwidReader $reader, Kind $kind, ?DateTimeImmutable $since = null): array
    {
        $nodes = [];
        $cursor = null;
        do {
            $page = $reader->page($kind, $cursor, $since, '');
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
        return new ScriptedTransport(new FixtureResponder('ecwid', $state));
    }

    private function reader(ScriptedTransport $transport): EcwidReader
    {
        return EcwidReader::connect(self::API_HOST, self::STORE_ID, self::TOKEN, $transport, $this->sleeper);
    }
}
