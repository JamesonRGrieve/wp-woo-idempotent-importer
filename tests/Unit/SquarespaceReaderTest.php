<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\Squarespace\SquarespaceAuthorizer;
use IdempotentImporter\Source\Squarespace\SquarespaceReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\Responder;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class SquarespaceReaderTest extends TestCase
{
    private const API_BASE = 'https://api.squarespace.test';
    private const API_KEY = 'fixture-api-key';
    private const NOW = '2026-09-12T08:30:00.250-03:00';
    private const MARIN = '6c3000000000000000000021';
    private const OSCAR = '6c3000000000000000000024';
    private const ORDER_1 = '6d4000000000000000000031';

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
    }

    public function testReadsTheWebsiteWithTheApiKeyAndAUserAgent(): void
    {
        $transport = self::transport('v1');

        $website = $this->reader($transport)->shop();

        self::assertSame('gid://squarespace/Website/5f3c3d55ac435e1a051f7701', $website->id());
        self::assertSame('Harbour & Pine', $website->string('title'));
        self::assertSame([self::API_BASE . '/1.0/authorization/website'], array_column($transport->requests, 'url'));
        self::assertSame('Bearer ' . self::API_KEY, $transport->requests[0]['headers']['Authorization']);
        self::assertSame(SquarespaceAuthorizer::USER_AGENT, $transport->requests[0]['headers']['User-Agent'], 'Squarespace rejects requests without one');
    }

    public function testListsArePagedByCursorAndOnlyTheFirstRequestCarriesFilters(): void
    {
        $transport = self::transport('v1');
        $reader = $this->reader($transport);

        $products = $this->all($reader, Kind::Product);

        self::assertSame([
            'gid://squarespace/Product/6b2000000000000000000011', 'gid://squarespace/Product/6b2000000000000000000012',
            'gid://squarespace/Product/6b2000000000000000000013', 'gid://squarespace/Product/6b2000000000000000000014',
            'gid://squarespace/Product/6b2000000000000000000015',
        ], self::gids($products));
        self::assertSame([
            self::API_BASE . '/v2/commerce/products',
            self::API_BASE . '/v2/commerce/products?cursor=eyJwcm9kdWN0cyI6IjIifQ',
        ], array_column($transport->requests, 'url'));
        self::assertSame(['gid://squarespace/StorePage/6a1000000000000000000001', 'gid://squarespace/StorePage/6a1000000000000000000002'], self::gids($this->all($reader, Kind::Collection)));
    }

    public function testIncrementalRunsAskForAModificationWindowWhereSquarespaceCanFilter(): void
    {
        $transport = self::transport('v2');
        $reader = $this->reader($transport);
        $since = new DateTimeImmutable('2026-09-05T06:00:00-06:00');

        $reader->page(Kind::Product, null, $since, '');
        $reader->page(Kind::Order, null, $since, '');

        $window = 'modifiedAfter=2026-09-05T12%3A00%3A00.000Z&modifiedBefore=2026-09-12T11%3A30%3A00.250Z';
        self::assertSame(self::API_BASE . '/v2/commerce/products?' . $window, $transport->requests[0]['url']);
        self::assertSame(self::API_BASE . '/1.0/commerce/orders?paymentStates=' . rawurlencode(SquarespaceReader::ORDER_PAYMENT_STATES) . '&' . $window, $transport->requests[1]['url'], 'every payment state, then the window');
        foreach (Kind::SYNC_ORDER as $kind) {
            self::assertSame(in_array($kind, [Kind::Product, Kind::Order], true), $reader->isIncremental($kind), $kind->value);
        }
    }

    public function testOrdersArriveWithTheirTransactionsAndTheirCustomersProfile(): void
    {
        $transport = self::transport('v2');

        $orders = $this->all($this->reader($transport), Kind::Order);

        self::assertSame([
            'gid://squarespace/Order/6d4000000000000000000031', 'gid://squarespace/Order/6d4000000000000000000032',
            'gid://squarespace/Order/6d4000000000000000000033',
        ], self::gids($orders));
        self::assertSame(['b7c8d9e0-0000-4000-8000-000000009001'], array_map(
            static fn(Node $refund): string => $refund->string('id'),
            $orders[0]->list('transactions')[0]->list('payments')[0]->list('refunds'),
        ));
        self::assertSame(self::MARIN, $orders[0]->child('customer_profile')?->string('id'));
        self::assertNull($orders[1]->child('customer_profile'), 'a guest order');
        self::assertSame([], $orders[1]->list('transactions'), 'an unpaid order has no transactions');
        self::assertSame(self::OSCAR, $orders[2]->child('customer_profile')?->string('id'));
        self::assertSame([
            self::API_BASE . '/1.0/commerce/orders?paymentStates=' . rawurlencode(SquarespaceReader::ORDER_PAYMENT_STATES),
            self::API_BASE . '/1.0/profiles/' . self::MARIN . ',' . self::OSCAR,
            self::API_BASE . '/1.0/commerce/transactions?orderId=6d4000000000000000000031',
            self::API_BASE . '/1.0/commerce/transactions?orderId=6d4000000000000000000032',
            self::API_BASE . '/1.0/commerce/transactions?orderId=6d4000000000000000000033',
        ], array_column($transport->requests, 'url'), "one profiles read for the page's customers, then each order's transactions");
    }

    public function testAProfileBatchSquarespaceRejectsIsReadOneProfileAtATime(): void
    {
        $fixtures = new FixtureResponder('squarespace', 'v2');
        $batch = '/1.0/profiles/' . self::MARIN . ',' . self::OSCAR;
        $responder = new class ($fixtures, $batch) implements Responder {
            public function __construct(private readonly FixtureResponder $fixtures, private readonly string $batch) {}

            public function respond(string $method, string $url, string $body): HttpResponse
            {
                if (str_ends_with($url, $this->batch)) {
                    return new HttpResponse(404, '{}');
                }
                $profile = preg_match('#/1\.0/profiles/([0-9a-f]+)$#', $url, $match) === 1 ? $match[1] : null;

                return $profile === null
                    ? $this->fixtures->respond($method, $url, $body)
                    : new HttpResponse(200, '{"profiles":[{"id":"' . $profile . '","isCustomer":true}]}');
            }
        };
        $transport = new ScriptedTransport($responder);

        $orders = $this->all($this->reader($transport), Kind::Order);

        self::assertSame(self::MARIN, $orders[0]->child('customer_profile')?->string('id'));
        self::assertSame(self::OSCAR, $orders[2]->child('customer_profile')?->string('id'));
        self::assertSame(
            [self::API_BASE . $batch, self::API_BASE . '/1.0/profiles/' . self::MARIN, self::API_BASE . '/1.0/profiles/' . self::OSCAR],
            array_slice(array_column($transport->requests, 'url'), 1, 3),
        );
    }

    public function testDiscountsArePagedByOffset(): void
    {
        $first = '{"discounts":[{"id":"6e5000000000000000000041","name":"A"}],"hasNextPage":true,"hasPreviousPage":false}';
        $transport = new ScriptedTransport(null, [new HttpResponse(200, $first), new HttpResponse(200, '{"discounts":[],"hasNextPage":false}')]);
        $reader = $this->reader($transport);

        $page = $reader->page(Kind::Discount, null, null, '');
        $last = $reader->page(Kind::Discount, $page->endCursor, null, '');

        self::assertSame(['gid://squarespace/Discount/6e5000000000000000000041'], self::gids($page->nodes));
        self::assertTrue($page->hasNextPage);
        self::assertSame('1', $page->endCursor);
        self::assertFalse($last->hasNextPage);
        self::assertSame(self::API_BASE . '/v1/commerce/discounts?status=ALL&sortBy=CREATED_ON&sortDirection=ASCENDING&offset=0&limit=1000', $transport->requests[0]['url']);
        self::assertStringContainsString('&offset=1&', $transport->requests[1]['url']);
        self::assertCount(5, $this->all($this->reader(self::transport('v1')), Kind::Discount));
    }

    public function testOneObjectByItsIdentity(): void
    {
        $reader = $this->reader(self::transport('v1'));

        self::assertSame('Cedar cutting board', $reader->node(Kind::Product, 'gid://squarespace/Product/6b2000000000000000000011')?->string('name'));
        self::assertNull($reader->node(Kind::Product, 'gid://squarespace/Product/6b2000000000000000000099'), 'a product Squarespace no longer has is null');
        self::assertSame('Marin', $reader->node(Kind::Customer, 'gid://squarespace/Profile/' . self::MARIN)?->string('firstName'));
        self::assertSame('Welcome 10%', $reader->node(Kind::Discount, 'gid://squarespace/Discount/6e5000000000000000000041')?->string('name'));
        $order = $reader->node(Kind::Order, 'gid://squarespace/Order/' . self::ORDER_1);
        self::assertSame('gid://squarespace/Order/' . self::ORDER_1, $order?->id());
        self::assertCount(1, $order->list('transactions'));
        self::assertNull($reader->node(Kind::Order, 'gid://squarespace/Order/6d4000000000000000000099'));
    }

    public function testKindsSquarespaceCannotReadAreRefused(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $refused = [];
        foreach ([[Kind::Collection, 'gid://squarespace/StorePage/6a1000000000000000000001'], [Kind::Product, 'gid://squarespace/Product/a,b']] as [$kind, $gid]) {
            try {
                $reader->node($kind, $gid);
            } catch (SourceError) {
                $refused[] = $gid;
            }
        }
        self::assertCount(2, $refused, 'a store page has no single read; an id that is not a Squarespace id is refused');
        $this->expectException(SourceError::class);
        $reader->page(Kind::Page, null, null, '');
    }

    public function testASiteWithoutAnApiKeySaysSo(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Squarespace is not configured');
        SquarespaceReader::connect(self::API_BASE, '', new ScriptedTransport(), $this->sleeper, new DateTimeImmutable(self::NOW));
    }

    public function testARejectedKeyIsNotRetried(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('squarespace', 'v1'), [new HttpResponse(401, '{}')]);

        try {
            $this->reader($transport)->shop();
            self::fail('a rejected key is an error');
        } catch (SourceError $e) {
            self::assertStringContainsString('Squarespace rejected the credentials', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
        self::assertCount(1, $transport->requests, 'a static key has nothing to renew');
    }

    public function testRateLimitingWaitsOutSquarespacesOneMinuteCooldown(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('squarespace', 'v1'), [new HttpResponse(429, '{}')]);

        $this->reader($transport)->page(Kind::Collection, null, null, '');

        self::assertSame([SquarespaceReader::RATE_LIMIT_COOLDOWN_SECONDS], $this->sleeper->waits);
        self::assertSame(60.0, SquarespaceReader::RATE_LIMIT_COOLDOWN_SECONDS);
    }

    /** @return list<Node> every node of a kind, following the cursor to the end */
    private function all(SquarespaceReader $reader, Kind $kind): array
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
        return new ScriptedTransport(new FixtureResponder('squarespace', $state));
    }

    private function reader(ScriptedTransport $transport): SquarespaceReader
    {
        return SquarespaceReader::connect(self::API_BASE, self::API_KEY, $transport, $this->sleeper, new DateTimeImmutable(self::NOW));
    }
}
