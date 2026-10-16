<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SquareOnline\SquareOnlineAuthorizer;
use IdempotentImporter\Source\SquareOnline\SquareOnlineReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class SquareOnlineReaderTest extends TestCase
{
    private const API_BASE = 'https://connect.squareup.test';
    private const TOKEN = 'fixture-access-token';

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
    }

    public function testReadsTheSellerWithItsLocationsAndSitesAsTheSeller(): void
    {
        $transport = self::transport('v1');

        $shop = $this->reader($transport)->shop();

        self::assertSame('gid://square_online/Merchant/MRCHNT1', $shop->id());
        self::assertSame(['LOCMAIN1', 'LOCMARKET2'], array_map(static fn(Node $location): string => $location->string('id'), $shop->list('locations')));
        self::assertSame('prairiepottery.square.site', $shop->list('sites')[0]->string('domain'));
        self::assertSame(
            [self::API_BASE . '/v2/merchants/me', self::API_BASE . '/v2/sites', self::API_BASE . '/v2/locations'],
            array_column($transport->requests, 'url'),
        );
        self::assertSame('Bearer ' . self::TOKEN, $transport->requests[0]['headers']['Authorization']);
        self::assertSame(SquareOnlineAuthorizer::API_VERSION, $transport->requests[0]['headers']['Square-Version']);
    }

    public function testItemsArriveWithWhatTheyReferenceAndTheirStockAtTheMainLocation(): void
    {
        $transport = self::transport('v1');

        $items = $this->all($this->reader($transport), Kind::Product);

        self::assertSame(
            ['gid://square_online/Item/ITEM_MUG', 'gid://square_online/Item/ITEM_APRON', 'gid://square_online/Item/ITEM_SAMPLE', 'gid://square_online/Item/ITEM_GIFT'],
            array_map(static fn(Node $node): string => $node->id(), $items),
        );
        $apron = $items[1];
        self::assertSame(['OPT_COLOR'], self::ids($apron->list('option_objects')), 'item options are read in a batch (not among related objects)');
        self::assertSame(['IMG_APRON', 'IMG_APRON_CHAR'], self::ids($apron->list('image_objects')), 'the variation image is read too');
        self::assertSame(['CAT_LINEN'], self::ids($apron->list('category_objects')));
        self::assertSame(['VAR_APRON_NAT', 'VAR_APRON_CHAR'], array_map(static fn(Node $count): string => $count->string('catalog_object_id'), $apron->list('inventory_counts')));
        self::assertSame('LOCMAIN1', $apron->string('main_location_id'));
        $search = self::body($transport, 0);
        self::assertSame(['object_types' => ['ITEM'], 'include_related_objects' => true, 'limit' => SquareOnlineReader::CATALOG_PAGE_SIZE], $search);
        self::assertSame(['IMG_APRON_CHAR', 'OPT_COLOR'], self::body($transport, 1)['object_ids'] ?? null);
        $stock = self::body($transport, 3);
        self::assertSame(['VAR_APRON_CHAR', 'VAR_APRON_NAT', 'VAR_GIFT', 'VAR_MUG', 'VAR_SAMPLE'], $stock['catalog_object_ids'] ?? null);
        self::assertSame(['LOCMAIN1'], $stock['location_ids'] ?? null);
        self::assertSame(self::API_BASE . '/v2/merchants/me', $transport->requests[2]['url'], 'the main location comes from the seller');
        self::assertCount(4, $transport->requests, 'one search, one batch of missing objects, the seller once, one stock read');
    }

    public function testCustomersArePagedByCursor(): void
    {
        $transport = self::transport('v1');

        $customers = $this->all($this->reader($transport), Kind::Customer);

        self::assertSame(['gid://square_online/Customer/CUST_ADA', 'gid://square_online/Customer/CUST_BOB'], array_map(static fn(Node $node): string => $node->id(), $customers));
        self::assertStringContainsString('/v2/customers?limit=100&sort_field=CREATED_AT&sort_order=ASC', $transport->requests[0]['url']);
        self::assertStringEndsWith('&cursor=CUSTOMERS-PAGE-2', $transport->requests[1]['url']);
    }

    public function testOrdersAreSearchedAcrossLocationsAndNameTheItemOfEachLine(): void
    {
        $transport = self::transport('v1');

        $orders = $this->all($this->reader($transport), Kind::Order);

        self::assertSame(['gid://square_online/Order/ORDER_1', 'gid://square_online/Order/ORDER_2'], array_map(static fn(Node $node): string => $node->id(), $orders));
        self::assertSame(['VAR_MUG' => 'ITEM_MUG', 'VAR_APRON_CHAR' => 'ITEM_APRON'], $orders[0]->raw()['variation_items'] ?? null);
        $search = self::body($transport, 1);
        self::assertSame(['LOCMAIN1', 'LOCMARKET2'], $search['location_ids'] ?? null);
        self::assertSame([
            'filter' => ['state_filter' => ['states' => SquareOnlineReader::ORDER_STATES]],
            'sort' => ['sort_field' => 'CREATED_AT', 'sort_order' => 'ASC'],
        ], $search['query'] ?? null);
        self::assertSame(['object_ids' => ['VAR_APRON_CHAR', 'VAR_MUG'], 'include_related_objects' => false], self::body($transport, 2));
    }

    public function testIncrementalRunsAskOnlyForWhatChangedAndSeeDeletions(): void
    {
        $transport = self::transport('v2');
        $reader = $this->reader($transport);
        $since = new DateTimeImmutable('2026-09-05T06:00:00-06:00');

        $changed = $reader->page(Kind::Product, null, $since, '');

        self::assertSame(['object_types' => ['ITEM'], 'include_related_objects' => true, 'limit' => 100, 'begin_time' => '2026-09-05T12:00:00Z', 'include_deleted_objects' => true], self::body($transport, 0));
        self::assertSame(['gid://square_online/Item/ITEM_MUG', 'gid://square_online/Item/ITEM_SAMPLE'], array_map(static fn(Node $node): string => $node->id(), $changed->nodes));
        self::assertTrue($changed->nodes[1]->bool('is_deleted'), 'a deleted item comes through, to be retired');
        self::assertTrue($changed->hasNextPage, 'then the stock phase');
        $restocked = $reader->page(Kind::Product, $changed->endCursor, $since, '');
        self::assertSame(['gid://square_online/Item/ITEM_MUG'], array_map(static fn(Node $node): string => $node->id(), $restocked->nodes));
        self::assertFalse($restocked->hasNextPage);
        self::assertSame(['location_ids' => ['LOCMAIN1'], 'states' => ['IN_STOCK'], 'updated_after' => '2026-09-05T12:00:00Z'], self::bodies($transport, '/v2/inventory/counts/batch-retrieve')[1]);

        $reader->page(Kind::Customer, null, $since, '');
        $reader->page(Kind::Order, null, $since, '');
        $customers = (new Node(self::bodies($transport, '/v2/customers/search')[0]))->requiredChild('query');
        self::assertSame(['updated_at' => ['start_at' => '2026-09-05T12:00:00Z']], $customers->requiredChild('filter')->raw());
        $orders = (new Node(self::bodies($transport, '/v2/orders/search')[0]))->requiredChild('query');
        self::assertSame(['updated_at' => ['start_at' => '2026-09-05T12:00:00Z']], $orders->requiredChild('filter')->requiredChild('date_time_filter')->raw());
        self::assertSame('UPDATED_AT', $orders->requiredChild('sort')->string('sort_field'), 'Square requires the sort field to match the time filter');
        foreach ([Kind::Collection, Kind::Product, Kind::Customer, Kind::Order] as $kind) {
            self::assertTrue($reader->isIncremental($kind));
        }
        self::assertFalse($reader->isIncremental(Kind::Shop));
    }

    public function testAFullRunReadsNoStockPhase(): void
    {
        $page = $this->reader(self::transport('v1'))->page(Kind::Product, null, null, '');

        self::assertFalse($page->hasNextPage);
        self::assertNull($page->endCursor);
    }

    public function testOneObjectByItsIdentity(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $mug = $reader->node(Kind::Product, 'gid://square_online/Item/ITEM_MUG');

        self::assertSame('gid://square_online/Item/ITEM_MUG', $mug?->id());
        self::assertSame('4', $mug->list('inventory_counts')[0]->string('quantity'));
        self::assertNull($reader->node(Kind::Product, 'gid://square_online/Item/ITEM_GONE'), 'an object Square no longer has is null');
        self::assertNull($reader->node(Kind::Collection, 'gid://square_online/Category/CAT_GONE'));
    }

    public function testAnAccountWithoutATokenSaysSo(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Square Online is not configured');
        SquareOnlineReader::connect(self::API_BASE, '', new ScriptedTransport(), $this->sleeper);
    }

    public function testARejectedTokenIsNotRetried(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('square_online', 'v1'), [new HttpResponse(401, '{"errors":[]}')]);

        try {
            $this->reader($transport)->shop();
            self::fail('a rejected token is an error');
        } catch (SourceError $e) {
            self::assertStringContainsString('rejected the credentials', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
        self::assertCount(1, $transport->requests, 'a static token has nothing to renew');
    }

    public function testRateLimitsAreWaitedOut(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('square_online', 'v1'), [new HttpResponse(429, '{}', '2')]);

        $this->reader($transport)->page(Kind::Collection, null, null, '');

        self::assertSame([2.0], $this->sleeper->waits);
    }

    public function testKindsSquareDoesNotHaveAreRefused(): void
    {
        $reader = $this->reader(self::transport('v1'));

        try {
            $reader->node(Kind::Review, 'gid://square_online/Review/1');
            self::fail('Square has no reviews');
        } catch (SourceError) {
            $this->expectException(SourceError::class);
            $reader->page(Kind::Menu, null, null, '');
        }
    }

    /** @return list<Node> every node of a kind, following the cursor to the end */
    private function all(SquareOnlineReader $reader, Kind $kind): array
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
     * @param list<Node> $objects
     *
     * @return list<string>
     */
    private static function ids(array $objects): array
    {
        return array_map(static fn(Node $object): string => $object->string('id'), $objects);
    }

    /** @return array<array-key, mixed> a request's JSON body */
    private static function body(ScriptedTransport $transport, int $request): array
    {
        $decoded = json_decode($transport->requests[$request]['body'], true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array<array-key, mixed>> the JSON bodies of every request to a path, in order */
    private static function bodies(ScriptedTransport $transport, string $path): array
    {
        $bodies = [];
        foreach ($transport->requests as $index => $request) {
            if (parse_url($request['url'], PHP_URL_PATH) === $path) {
                $bodies[] = self::body($transport, $index);
            }
        }

        return $bodies;
    }

    private static function transport(string $state): ScriptedTransport
    {
        return new ScriptedTransport(new FixtureResponder('square_online', $state));
    }

    private function reader(ScriptedTransport $transport): SquareOnlineReader
    {
        return SquareOnlineReader::connect(self::API_BASE, self::TOKEN, $transport, $this->sleeper);
    }
}
