<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Magento\MagentoReader;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class MagentoReaderTest extends TestCase
{
    private const STORE_URL = 'https://shop.magento.test/';
    private const TOKEN = 'fixture-access-token';
    private const API = 'https://shop.magento.test/rest/default/V1';
    private const FILTER = 'searchCriteria[filterGroups][0][filters][0]';
    private const SECOND_FILTER = 'searchCriteria[filterGroups][1][filters][0]';

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
    }

    public function testReadsTheStoreViewWithTheIntegrationToken(): void
    {
        $transport = self::transport('v1');

        $store = $this->reader($transport)->shop();

        self::assertSame('gid://magento/Store/1', $store->id());
        self::assertSame('America/Vancouver', $store->string('timezone'));
        self::assertSame('Harbourside Goods', $store->requiredChild('store_group')->string('name'));
        self::assertSame('Main Website', $store->requiredChild('website')->string('name'));
        self::assertSame('CAD', $store->requiredChild('currency')->string('base_currency_code'));
        self::assertSame([
            self::API . '/store/storeViews',
            self::API . '/store/storeConfigs',
            self::API . '/store/storeGroups',
            self::API . '/store/websites',
            self::API . '/directory/currency',
        ], array_column($transport->requests, 'url'), 'a blank store code is the default store view');
        self::assertSame('Bearer ' . self::TOKEN, $transport->requests[0]['headers']['Authorization']);
        self::assertSame('application/json', $transport->requests[0]['headers']['Accept']);
    }

    public function testProductsAreTheVisibleOnesWithTheStoreAndConfigurableChildren(): void
    {
        $transport = self::transport('v1');

        $products = $this->all($this->reader($transport), Kind::Product);

        self::assertSame(
            ['gid://magento/Product/11', 'gid://magento/Product/12', 'gid://magento/Product/13', 'gid://magento/Product/14', 'gid://magento/Product/15'],
            self::gids($products),
        );
        $jacket = $products[1];
        self::assertSame([121, 122], array_map(static fn(Node $child): int => $child->int('id'), $jacket->list('children')));
        self::assertSame(['color'], array_map(static fn(Node $attribute): string => $attribute->string('attribute_code'), $jacket->list('attributes')));
        self::assertSame([], $products[0]->list('children'), 'a simple product has no children');
        $store = $products[0]->requiredChild('store');
        self::assertSame('http://magento-fake:8080/media/', $store->string('media_url'));
        self::assertSame(1, $store->int('website_id'));
        self::assertSame([1, 0, 2], $store->ints('root_category_ids'), 'the root catalog and every store root');
        self::assertSame('kgs', $store->string('weight_unit'));
        $urls = array_column($transport->requests, 'url');
        self::assertSame(
            [self::FILTER . '[field]' => 'visibility', self::FILTER . '[value]' => '1', self::FILTER . '[conditionType]' => 'neq',
                'searchCriteria[sortOrders][0][field]' => 'entity_id', 'searchCriteria[sortOrders][0][direction]' => 'ASC',
                'searchCriteria[pageSize]' => (string) MagentoReader::PAGE_SIZE, 'searchCriteria[currentPage]' => '1'],
            self::query($urls[0]),
            'products not visible individually are configurable children',
        );
        self::assertCount(1, array_filter($urls, static fn(string $url): bool => str_contains($url, '/products/attributes/93')), 'each attribute is read once');
        self::assertContains('121,122', array_map(static fn(string $url): string => self::query($url)[self::FILTER . '[value]'] ?? '', $urls), "the children are read by the parent's links");
    }

    public function testSearchesArePagedUntilTheTotalCountIsRead(): void
    {
        $transport = self::transport('v2');

        $customers = $this->all($this->reader($transport), Kind::Customer);

        self::assertSame(['gid://magento/Customer/41', 'gid://magento/Customer/42', 'gid://magento/Customer/43'], self::gids($customers));
        self::assertSame(['1', '2'], array_map(static fn(array $request): string => self::query($request['url'])['searchCriteria[currentPage]'] ?? '', $transport->requests));
    }

    public function testAPagePastTheEndIsNeverRequested(): void
    {
        $transport = self::transport('v1');

        $page = $this->reader($transport)->page(Kind::Customer, null, null, '');

        self::assertFalse($page->hasNextPage, 'two of two read: Magento would answer page 2 with page 1 again');
        self::assertCount(1, $transport->requests);
    }

    public function testIncrementalRunsAskForWhatChangedWhereMagentoCanFilter(): void
    {
        $transport = self::transport('v2');
        $reader = $this->reader($transport);
        $since = new DateTimeImmutable('2026-09-05T06:00:00-07:00');

        $reader->page(Kind::Customer, null, $since, '');
        $reader->page(Kind::Order, null, $since, '');

        $customers = self::query($transport->requests[0]['url']);
        self::assertSame(['updated_at', '2026-09-05 13:00:00', 'gt'], [$customers[self::FILTER . '[field]'], $customers[self::FILTER . '[value]'], $customers[self::FILTER . '[conditionType]']]);
        $orders = self::query((string) array_values(array_filter(array_column($transport->requests, 'url'), static fn(string $url): bool => str_contains($url, '/V1/orders?')))[0]);
        self::assertSame(['store_id', '1', 'in'], [$orders[self::FILTER . '[field]'], $orders[self::FILTER . '[value]'], $orders[self::FILTER . '[conditionType]']], "the website's store views");
        self::assertSame(['updated_at', '2026-09-05 13:00:00', 'gt'], [$orders[self::SECOND_FILTER . '[field]'], $orders[self::SECOND_FILTER . '[value]'], $orders[self::SECOND_FILTER . '[conditionType]']]);
        foreach (Kind::SYNC_ORDER as $kind) {
            self::assertSame(in_array($kind, [Kind::Customer, Kind::Order], true), $reader->isIncremental($kind), $kind->value);
        }
    }

    public function testRulesArriveWithTheirCouponsAndEveryCustomerGroup(): void
    {
        $transport = self::transport('v1');

        $rules = $this->all($this->reader($transport), Kind::Discount);

        self::assertSame(
            ['gid://magento/SalesRule/61', 'gid://magento/SalesRule/62', 'gid://magento/SalesRule/63', 'gid://magento/SalesRule/64', 'gid://magento/SalesRule/65', 'gid://magento/SalesRule/66'],
            self::gids($rules),
        );
        self::assertSame(['MAG-WELCOME5'], array_map(static fn(Node $coupon): string => $coupon->string('code'), $rules[0]->list('coupons')));
        self::assertSame([], $rules[3]->list('coupons'), 'a rule without a coupon');
        self::assertSame([0, 1, 2, 3], $rules[0]->ints('all_customer_group_ids'));
        self::assertSame('America/Vancouver', $rules[0]->requiredChild('store')->string('timezone'));
        self::assertSame('61,62,63,64,65,66', self::query(array_column($transport->requests, 'url')[1])[self::FILTER . '[value]'], 'one coupon search for the page');
    }

    public function testOrdersArriveWithTheirCreditMemosAndShipments(): void
    {
        $transport = self::transport('v2');

        $orders = $this->all($this->reader($transport), Kind::Order);

        self::assertSame(['gid://magento/Order/501', 'gid://magento/Order/502', 'gid://magento/Order/503'], self::gids($orders));
        self::assertSame([901], array_map(static fn(Node $memo): int => $memo->int('entity_id'), $orders[0]->list('credit_memos')));
        self::assertSame([801], array_map(static fn(Node $shipment): int => $shipment->int('entity_id'), $orders[0]->list('shipments')));
        self::assertSame([], $orders[1]->list('credit_memos'));
        $searches = array_values(array_filter(array_column($transport->requests, 'url'), static fn(string $url): bool => str_contains($url, 'creditmemos') || str_contains($url, 'shipments')));
        self::assertCount(2, $searches, 'credit memos and shipments read once for the page');
        self::assertSame('501,502,503', self::query($searches[0])[self::FILTER . '[value]']);
    }

    public function testCategoriesAreThoseBelowTheStoreRoots(): void
    {
        $transport = self::transport('v1');

        $categories = $this->all($this->reader($transport), Kind::Collection);

        self::assertSame(['gid://magento/Category/3', 'gid://magento/Category/4', 'gid://magento/Category/5'], self::gids($categories));
        self::assertSame(['level', '1', 'gt'], array_values(array_slice(self::query($transport->requests[0]['url']), 0, 3)));
        self::assertSame('http://magento-fake:8080/', $categories[0]->requiredChild('store')->string('base_url'));
    }

    public function testOneObjectByItsIdentity(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $kettle = $reader->node(Kind::Product, 'gid://magento/Product/11');

        self::assertSame('gid://magento/Product/11', $kettle?->id());
        self::assertSame('MAG-KETTLE-1', $kettle->string('sku'));
        self::assertNull($reader->node(Kind::Product, 'gid://magento/Product/999'), 'a product Magento no longer has is null');
        self::assertNull($reader->node(Kind::Order, 'gid://magento/Order/999'));
    }

    public function testKindsMagentoCannotReadAreRefused(): void
    {
        $reader = $this->reader(self::transport('v1'));

        try {
            $reader->node(Kind::Review, 'gid://magento/Review/1');
            self::fail('Magento has no reviews API');
        } catch (SourceError) {
            $this->expectException(SourceError::class);
            $reader->page(Kind::Blog, null, null, '');
        }
    }

    public function testAStoreWithoutAUrlStoreCodeOrTokenSaysSo(): void
    {
        foreach ([['', 'default', self::TOKEN, 'store URL'], [self::STORE_URL, 'Default View', self::TOKEN, 'store view code'], [self::STORE_URL, '', '', 'access token']] as [$url, $code, $token, $named]) {
            try {
                MagentoReader::connect($url, $code, $token, new ScriptedTransport(), $this->sleeper);
                self::fail("the {$named} is required");
            } catch (SourceError $e) {
                self::assertStringContainsString($named, $e->getMessage());
            }
        }
    }

    public function testAnotherStoreViewIsReadUnderItsCode(): void
    {
        $transport = new ScriptedTransport(null, [new HttpResponse(200, '[]')]);

        try {
            MagentoReader::connect(self::STORE_URL, 'french', self::TOKEN, $transport, $this->sleeper)->shop();
            self::fail('a store code the store does not have');
        } catch (SourceError $e) {
            self::assertStringContainsString('"french"', $e->getMessage());
        }
        self::assertSame('https://shop.magento.test/rest/french/V1/store/storeViews', $transport->requests[0]['url']);
    }

    public function testARejectedTokenStopsTheRunWithoutARetry(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('magento', 'v1'), [new HttpResponse(401, '{"message":"The consumer isn\'t authorized to access %resources."}')]);

        try {
            $this->reader($transport)->shop();
            self::fail('a rejected token is an error');
        } catch (CredentialsRejected $e) {
            self::assertStringContainsString('rejected the credentials', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
        self::assertCount(1, $transport->requests, 'a static token has nothing to renew');
    }

    public function testRateLimitingWaitsAsTheStoreSays(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('magento', 'v1'), [new HttpResponse(429, '{}', '2')]);

        $this->reader($transport)->page(Kind::Page, null, null, '');

        self::assertSame([2.0], $this->sleeper->waits, 'Retry-After (Fastly on Adobe Commerce Cloud); Magento itself sets no request rate, so nothing else is paced');
        self::assertCount(2, $transport->requests);
    }

    /** @return list<Node> every node of a kind, following the cursor to the end */
    private function all(MagentoReader $reader, Kind $kind): array
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

    /** @return array<string, string> a request's query parameters, decoded, in order */
    private static function query(string $url): array
    {
        $query = [];
        foreach (explode('&', (string) parse_url($url, PHP_URL_QUERY)) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[rawurldecode($name)] = rawurldecode($value);
        }

        return $query;
    }

    private static function transport(string $state): ScriptedTransport
    {
        return new ScriptedTransport(new FixtureResponder('magento', $state));
    }

    private function reader(ScriptedTransport $transport): MagentoReader
    {
        return MagentoReader::connect(self::STORE_URL, '', self::TOKEN, $transport, $this->sleeper);
    }
}
