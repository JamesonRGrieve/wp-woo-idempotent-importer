<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Shopify\Connection;
use IdempotentImporter\Source\Shopify\GraphQlClient;
use IdempotentImporter\Source\Shopify\QueryLibrary;
use IdempotentImporter\Source\Shopify\Reader;
use IdempotentImporter\Source\Shopify\ShopifyTokenAuthorizer;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use IdempotentImporter\Tests\Support\ShopifyResponder;
use PHPUnit\Framework\TestCase;

final class ReaderTest extends TestCase
{
    public function testReadsAPageFromTheFixtures(): void
    {
        $page = $this->reader(new ScriptedTransport(new ShopifyResponder('v1')))->page(Kind::Product, null, null, '');

        self::assertCount(3, $page->nodes);
        self::assertFalse($page->hasNextPage);
        self::assertSame('gid://shopify/Product/301', $page->nodes[0]->id());
    }

    public function testFollowsNestedConnectionsToTheEnd(): void
    {
        $firstPage = $this->productPage(['hasNextPage' => true, 'endCursor' => 'v1'], [['id' => 'gid://shopify/ProductVariant/1']]);
        $secondPage = json_encode(['data' => ['node' => ['variants' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'v2'],
            'nodes' => [['id' => 'gid://shopify/ProductVariant/2']],
        ]]]], JSON_THROW_ON_ERROR);
        $transport = new ScriptedTransport(null, [new HttpResponse(200, $firstPage), new HttpResponse(200, $secondPage)]);

        $page = $this->reader($transport)->page(Kind::Product, null, new DateTimeImmutable('2026-01-01T00:00:00Z'), '');

        self::assertSame(
            ['gid://shopify/ProductVariant/1', 'gid://shopify/ProductVariant/2'],
            array_map(static fn($variant): string => $variant->id(), $page->nodes[0]->list('variants')),
        );
        self::assertSame('ProductVariantsPage', $transport->operation(1));
        self::assertSame(['id' => 'gid://shopify/Product/1', 'after' => 'v1', 'first' => QueryLibrary::NESTED_PAGE_SIZE], $transport->variables(1));
        self::assertSame("updated_at:>='2026-01-01T00:00:00Z'", $transport->variables(0)['query'] ?? null);
    }

    public function testRefusesSilentTruncationOfOtherConnections(): void
    {
        $page = $this->productPage(['hasNextPage' => false, 'endCursor' => null], [[
            'id' => 'gid://shopify/ProductVariant/1',
            'metafields' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'm1'], 'nodes' => []],
        ]]);

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('variants.nodes.0.metafields has more entries than one query returns');
        $this->reader(new ScriptedTransport(null, [new HttpResponse(200, $page)]))->page(Kind::Product, null, null, '');
    }

    public function testNodeReturnsNullWhenShopifyNoLongerHasIt(): void
    {
        $transport = new ScriptedTransport(null, [new HttpResponse(200, '{"data":{"node":null}}')]);

        self::assertNull($this->reader($transport)->node(Kind::Product, 'gid://shopify/Product/9'));
    }

    public function testAnInventoryItemReadsItsProduct(): void
    {
        $transport = new ScriptedTransport(null, [
            new HttpResponse(200, '{"data":{"inventoryItem":{"variant":{"product":{"id":"gid://shopify/Product/7"}}}}}'),
            new HttpResponse(200, '{"data":{"node":null}}'),
        ]);

        self::assertNull($this->reader($transport)->node(Kind::Product, 'gid://shopify/InventoryItem/55'));
        self::assertSame('InventoryItemProduct', $transport->operation(0));
        self::assertSame('gid://shopify/Product/7', $transport->variables(1)['id'] ?? null);
    }

    public function testATooCostlyPageIsResentAtOnceWithSmallerPagesAndTheSizeThatFitIsReused(): void
    {
        $sleeper = new RecordingSleeper();
        $transport = new ScriptedTransport(null, [
            new HttpResponse(200, self::costRefusal(1941)),
            new HttpResponse(200, $this->productPage(['hasNextPage' => false, 'endCursor' => null], [], 'p1', true, 600)),
            new HttpResponse(200, $this->productPage(['hasNextPage' => false, 'endCursor' => null], [], null, false, 600)),
        ]);
        $reader = $this->reader($transport, $sleeper);

        $first = $reader->page(Kind::Product, null, null, '');
        $second = $reader->page(Kind::Product, 'p1', null, '');

        self::assertTrue($first->hasNextPage);
        self::assertFalse($second->hasNextPage);
        self::assertCount(3, $transport->requests, 'one refusal, then one request per page');
        self::assertSame([], $sleeper->waits, 'a cost refusal neither waits nor pauses');
        $refused = $transport->variables(0);
        $shrunk = $transport->variables(1);
        self::assertSame(QueryLibrary::PAGE_SIZE, $refused['first']);
        self::assertSame(QueryLibrary::NESTED_PAGE_SIZE, $refused['variantsFirst']);
        foreach (['first', 'variantsFirst', 'mediaFirst', 'collectionsFirst', 'metafieldsFirst'] as $size) {
            self::assertIsInt($shrunk[$size]);
            self::assertIsInt($refused[$size]);
            self::assertLessThan($refused[$size], $shrunk[$size], "{$size} shrinks with the page");
            self::assertGreaterThanOrEqual(1, $shrunk[$size]);
        }
        self::assertSame(23, $shrunk['first'], '50 × 900 / 1941, rounded down');
        $reused = $transport->variables(2);
        self::assertSame('p1', $reused['after']);
        self::assertGreaterThanOrEqual($shrunk['first'], $reused['first'], 'the next page starts from the size that fit');
        self::assertLessThan($refused['first'], $reused['first'], 'never back to the refused size');
    }

    public function testRepeatedRefusalsShrinkUntilTheQueryFits(): void
    {
        $transport = new ScriptedTransport(null, [
            new HttpResponse(200, self::costRefusal(3957)),
            new HttpResponse(200, self::costRefusal(2262, false)),
            new HttpResponse(200, self::costRefusal(1132)),
            new HttpResponse(200, '{"data":{"deliveryProfiles":{"pageInfo":{"hasNextPage":false,"endCursor":null},"nodes":[]}}}'),
        ]);

        $this->reader($transport)->page(Kind::DeliveryProfile, null, null, '');

        self::assertSame([50, 11, 4, 3], array_map(static fn(int $i): mixed => $transport->variables($i)['first'] ?? null, range(0, 3)));
    }

    public function testAnObjectTooCostlyEvenOneAtATimeFailsClearly(): void
    {
        $refusals = array_fill(0, 12, new HttpResponse(200, self::costRefusal(5000)));
        $transport = new ScriptedTransport(null, $refusals);

        try {
            $this->reader($transport)->page(Kind::DeliveryProfile, null, null, '');
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertSame("DeliveryProfileList: one object costs 5000 points even one at a time, over Shopify's single-query limit of 1000.", $e->getMessage());
        }
        self::assertSame(1, $transport->variables(count($transport->requests) - 1)['first'] ?? null, 'gave up only at a page of one');
        self::assertLessThan(12, count($transport->requests));
    }

    public function testTheShopIsReadWithItsPolicies(): void
    {
        $shop = $this->reader(new ScriptedTransport(new ShopifyResponder('v1')))->shop();

        self::assertSame('gid://shopify/Shop/1', $shop->id());
        self::assertCount(2, $shop->list('shopPolicies'));
        self::assertFalse($shop->has(Reader::POLICIES_DENIED));
    }

    public function testAMissingPoliciesScopeLeavesTheShopReadableAndNamesTheGap(): void
    {
        $denied = json_encode(['errors' => [[
            'message' => 'Access denied for shopPolicies field. Required access: `read_legal_policies` access scope.',
            'path' => ['shop', 'shopPolicies'],
            'extensions' => ['code' => 'ACCESS_DENIED', 'requiredAccess' => '`read_legal_policies` access scope.'],
        ]], 'data' => ['shop' => null]], JSON_THROW_ON_ERROR);
        $responder = new ShopifyResponder('v1');
        $transport = new ScriptedTransport(null, [
            $responder->respond('POST', '', '{"operationName":"Shop","variables":{}}'),
            new HttpResponse(200, $denied),
        ]);

        $shop = $this->reader($transport)->shop();

        self::assertSame('Fixture Boutique', $shop->string('name'));
        self::assertFalse($shop->has('shopPolicies'));
        self::assertStringContainsString('read_legal_policies', $shop->string(Reader::POLICIES_DENIED));
        self::assertSame(['Shop', 'ShopPolicies'], [$transport->operation(0), $transport->operation(1)]);
    }

    public function testOtherErrorsReadingPoliciesStillFail(): void
    {
        $responder = new ShopifyResponder('v1');
        $transport = new ScriptedTransport(null, [
            $responder->respond('POST', '', '{"operationName":"Shop","variables":{}}'),
            new HttpResponse(200, '{"errors":[{"message":"Internal error"}]}'),
        ]);

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('ShopPolicies: Internal error');
        $this->reader($transport)->shop();
    }

    private static function costRefusal(int $cost, bool $withExtensions = true): string
    {
        $message = "Query cost is {$cost}, which exceeds the single query max cost limit (1000).\n\nSee https://shopify.dev/docs/api/usage/rate-limits for more information.\n";

        return json_encode(['errors' => [[
            'message' => $message,
            'extensions' => $withExtensions ? ['code' => 'MAX_COST_EXCEEDED', 'cost' => $cost, 'maxCost' => 1000] : ['code' => 'MAX_COST_EXCEEDED'],
        ]]], JSON_THROW_ON_ERROR);
    }

    public function testAnInventoryItemWithoutAProductIsAnError(): void
    {
        $transport = new ScriptedTransport(null, [new HttpResponse(200, '{"data":{"inventoryItem":null}}')]);

        $this->expectException(SourceError::class);
        $this->reader($transport)->node(Kind::Product, 'gid://shopify/InventoryItem/55');
    }

    public function testDiscountsAreFetchedThroughTheirOwnRootField(): void
    {
        $transport = new ScriptedTransport(null, [new HttpResponse(200, '{"data":{"discountNode":null}}')]);
        $this->reader($transport)->node(Kind::Discount, 'gid://shopify/DiscountCodeNode/1');

        self::assertStringContainsString('discountNode(id: $id)', $transport->document(0));
    }

    /**
     * @param array{hasNextPage: bool, endCursor: ?string} $variantsPageInfo
     * @param list<array<string, mixed>> $variants
     */
    private function productPage(
        array $variantsPageInfo,
        array $variants,
        ?string $endCursor = 'p1',
        bool $hasNextPage = false,
        ?int $requestedCost = null,
    ): string {
        $response = ['data' => ['products' => [
            'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
            'nodes' => [[
                'id' => 'gid://shopify/Product/1',
                'variants' => ['pageInfo' => $variantsPageInfo, 'nodes' => $variants],
            ]],
        ]]];
        if ($requestedCost !== null) {
            $response['extensions'] = ['cost' => [
                'requestedQueryCost' => $requestedCost,
                'actualQueryCost' => 20,
                'throttleStatus' => ['maximumAvailable' => 20000, 'currentlyAvailable' => 19000, 'restoreRate' => 1000],
            ]];
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }

    private function reader(ScriptedTransport $transport, ?RecordingSleeper $sleeper = null): Reader
    {
        return new Reader(new GraphQlClient(Connection::forStore('fixture.myshopify.com'), QueryLibrary::bundled(), new ShopifyTokenAuthorizer('t'), $transport, $sleeper ?? new RecordingSleeper()));
    }
}
