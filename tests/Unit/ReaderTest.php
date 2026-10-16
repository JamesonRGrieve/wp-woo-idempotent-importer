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
        self::assertSame(['id' => 'gid://shopify/Product/1', 'after' => 'v1'], $transport->variables(1));
        self::assertSame("updated_at:>='2026-01-01T00:00:00Z'", $transport->variables(0)['query'] ?? null);
    }

    public function testRefusesSilentTruncationOfOtherConnections(): void
    {
        $page = $this->productPage(['hasNextPage' => false, 'endCursor' => null], [[
            'id' => 'gid://shopify/ProductVariant/1',
            'metafields' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'm1'], 'nodes' => []],
        ]]);

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('variants.nodes.0.metafields has more than 250 entries');
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
        self::assertSame(['id' => 'gid://shopify/Product/7'], $transport->variables(1));
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
    private function productPage(array $variantsPageInfo, array $variants): string
    {
        return json_encode(['data' => ['products' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'p1'],
            'nodes' => [[
                'id' => 'gid://shopify/Product/1',
                'variants' => ['pageInfo' => $variantsPageInfo, 'nodes' => $variants],
            ]],
        ]]], JSON_THROW_ON_ERROR);
    }

    private function reader(ScriptedTransport $transport): Reader
    {
        return new Reader(new GraphQlClient(Connection::forStore('fixture.myshopify.com', 't'), QueryLibrary::bundled(), $transport, new RecordingSleeper()));
    }
}
