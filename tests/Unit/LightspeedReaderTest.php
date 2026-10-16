<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Lightspeed\LightspeedAuthorizer;
use IdempotentImporter\Source\Lightspeed\LightspeedReader;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class LightspeedReaderTest extends TestCase
{
    private const API_BASE = 'https://api.lightspeed.test';
    private const LANGUAGE = 'en';
    private const KEY = 'fixture-api-key';
    private const SECRET = 'fixture-api-secret';
    private const SHOP = self::API_BASE . '/' . self::LANGUAGE;

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
    }

    public function testReadsTheShopAndItsWebsiteWithBasicAuthentication(): void
    {
        $transport = self::transport('v1');

        $shop = $this->reader($transport)->shop();

        self::assertSame('gid://lightspeed/Shop/7301', $shop->id());
        self::assertSame('Harbour Lane Goods', $shop->requiredChild('website')->string('name'));
        self::assertSame([self::SHOP . '/shop.json', self::SHOP . '/shop/website.json'], array_column($transport->requests, 'url'));
        self::assertSame('Basic ' . base64_encode(self::KEY . ':' . self::SECRET), $transport->requests[0]['headers'][LightspeedAuthorizer::HEADER]);
        self::assertSame('application/json', $transport->requests[0]['headers']['Accept']);
    }

    public function testProductsArriveWithTheirVariantsImagesCategoriesAndBrand(): void
    {
        $transport = self::transport('v1');

        $products = $this->all($this->reader($transport), Kind::Product);

        self::assertSame(['gid://lightspeed/Product/501', 'gid://lightspeed/Product/502', 'gid://lightspeed/Product/503'], self::gids($products));
        $towel = $products[1];
        self::assertSame([5022, 5021], array_map(static fn(Node $variant): int => $variant->int('id'), $towel->list('variants')));
        self::assertSame([5201], array_map(static fn(Node $image): int => $image->int('id'), $towel->list('images')));
        self::assertSame([402, 401], $towel->ints('category_ids'));
        self::assertSame('Harbour Lane', $towel->string('brand'));
        self::assertFalse($towel->bool('shop_is_b2b'));
        self::assertNull($products[2]->optionalString('brand'), 'a product without a brand');
        self::assertSame([
            self::SHOP . '/products.json?page=1&limit=' . LightspeedReader::PARENT_PAGE_SIZE,
            self::SHOP . '/shop.json',
            self::SHOP . '/categories/products.json?product=501',
            self::SHOP . '/variants.json?product=501&page=1&limit=250',
            self::SHOP . '/products/501/images.json',
            self::SHOP . '/brands/601.json',
            self::SHOP . '/categories/products.json?product=502',
            self::SHOP . '/variants.json?product=502&page=1&limit=250',
            self::SHOP . '/products/502/images.json',
            self::SHOP . '/categories/products.json?product=503',
            self::SHOP . '/variants.json?product=503&page=1&limit=250',
            self::SHOP . '/products/503/images.json',
        ], array_column($transport->requests, 'url'), 'the shop once, each brand once, each product\'s parts');
    }

    public function testListsArePagedByPageNumberUntilAShortPage(): void
    {
        $full = ['customers' => array_map(static fn(int $id): array => ['id' => $id, 'email' => "c{$id}@example.com"], range(1, LightspeedReader::PAGE_SIZE))];
        $transport = new ScriptedTransport(new FixtureResponder('lightspeed', 'v1'), [new HttpResponse(200, (string) json_encode($full))]);
        $reader = $this->reader($transport);

        $first = $reader->page(Kind::Customer, null, null, '');
        $second = $reader->page(Kind::Customer, $first->endCursor, null, '');

        self::assertCount(LightspeedReader::PAGE_SIZE, $first->nodes);
        self::assertTrue($first->hasNextPage, 'a full page may have more after it');
        self::assertSame('2', $first->endCursor);
        self::assertSame(['gid://lightspeed/Customer/801', 'gid://lightspeed/Customer/802'], self::gids($second->nodes));
        self::assertFalse($second->hasNextPage, 'a short page is the last');
        self::assertSame(self::SHOP . '/customers.json?page=2&limit=250', $transport->requests[1]['url']);
    }

    public function testOrdersArriveWithTheirLinesShipmentsCreditNotesAndTheShopsCurrency(): void
    {
        $transport = self::transport('v2');

        $orders = $this->all($this->reader($transport), Kind::Order);

        self::assertSame(['gid://lightspeed/Order/701', 'gid://lightspeed/Order/702', 'gid://lightspeed/Order/703'], self::gids($orders));
        $first = $orders[0];
        self::assertCount(2, $first->list('products'));
        self::assertSame('CP987654321CA', $first->list('shipments')[0]->string('trackingCode'));
        $creditNotes = $first->list('credit_notes');
        self::assertSame([9102], array_map(static fn(Node $note): int => $note->int('id'), $creditNotes), 'only the credit note, not the invoice');
        self::assertCount(2, $creditNotes[0]->list('items'));
        self::assertSame('CAD', $first->string('currency'));
        self::assertSame([], $orders[1]->list('credit_notes'));
        $urls = array_column($transport->requests, 'url');
        self::assertContains(self::SHOP . '/invoices.json?order=701&page=1&limit=250', $urls);
        self::assertContains(self::SHOP . '/invoices/9102/items.json', $urls);
        self::assertContains(self::SHOP . '/shipments.json?order=703&page=1&limit=250', $urls);
        self::assertContains(self::SHOP . '/orders/702/products.json?page=1&limit=250', $urls);
    }

    public function testIncrementalRunsReadWhatChangedAndTheParentsOfChangedChildren(): void
    {
        $transport = self::transport('v2');
        $reader = $this->reader($transport);
        $since = new DateTimeImmutable('2026-09-09T06:00:00-06:00');

        $products = $this->all($reader, Kind::Product, $since);
        $orders = $this->all($reader, Kind::Order, $since);
        $reader->page(Kind::Customer, null, $since, '');

        self::assertSame(['gid://lightspeed/Product/501', 'gid://lightspeed/Product/502', 'gid://lightspeed/Product/501'], self::gids($products), 'the mug again: its variant changed');
        self::assertSame(['gid://lightspeed/Order/701', 'gid://lightspeed/Order/702', 'gid://lightspeed/Order/703', 'gid://lightspeed/Order/701'], self::gids($orders), 'order 701 again: its credit note changed');
        $filter = 'updated_at_min=2026-09-08%2012%3A00%3A00';
        foreach (['/products.json?', '/variants.json?', '/orders.json?', '/shipments.json?', '/invoices.json?', '/customers.json?'] as $list) {
            self::assertNotEmpty(
                array_filter(array_column($transport->requests, 'url'), static fn(string $url): bool => str_contains($url, $list) && str_contains($url, $filter)),
                "{$list} asks from a day before the last run, in UTC",
            );
        }
        self::assertContains(self::SHOP . '/products/501.json', array_column($transport->requests, 'url'));
        self::assertContains(self::SHOP . '/orders/701.json', array_column($transport->requests, 'url'));
        foreach (Kind::SYNC_ORDER as $kind) {
            self::assertSame(in_array($kind, [Kind::Product, Kind::Customer, Kind::Order], true), $reader->isIncremental($kind), $kind->value);
        }
    }

    public function testAFullRunReadsNoChangedChildren(): void
    {
        $transport = self::transport('v2');

        $products = $this->all($this->reader($transport), Kind::Product);

        self::assertCount(2, $products);
        self::assertNotContains(self::SHOP . '/variants.json?page=1&limit=250', array_column($transport->requests, 'url'));
    }

    public function testArticlesCarryTheirBlogsUrlAndRedirectsTheShopsDomain(): void
    {
        $reader = $this->reader(self::transport('v2'));

        $articles = $this->all($reader, Kind::Article);
        $redirects = $this->all($reader, Kind::UrlRedirect);

        self::assertSame(['gid://lightspeed/Article/1301', 'gid://lightspeed/Article/1302'], self::gids($articles));
        self::assertSame('journal', $articles[0]->string('blog_url'));
        self::assertSame(['gid://lightspeed/Redirect/1401', 'gid://lightspeed/Redirect/1402'], self::gids($redirects));
        self::assertSame('harbourlane.example', $redirects[0]->string('shop_domain'));
    }

    public function testThePlainKinds(): void
    {
        $reader = $this->reader(self::transport('v1'));

        self::assertSame(['gid://lightspeed/Category/401', 'gid://lightspeed/Category/402'], self::gids($this->all($reader, Kind::Collection)));
        self::assertCount(5, $this->all($reader, Kind::Discount));
        self::assertSame(['gid://lightspeed/Textpage/1101', 'gid://lightspeed/Textpage/1102'], self::gids($this->all($reader, Kind::Page)));
        self::assertSame(['gid://lightspeed/Blog/1201'], self::gids($this->all($reader, Kind::Blog)));
        self::assertSame(['gid://lightspeed/Review/1501', 'gid://lightspeed/Review/1502'], self::gids($this->all($reader, Kind::Review)));
    }

    public function testOneObjectByItsIdentity(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $mug = $reader->node(Kind::Product, 'gid://lightspeed/Product/501');

        self::assertSame('gid://lightspeed/Product/501', $mug?->id());
        self::assertSame([5011], array_map(static fn(Node $variant): int => $variant->int('id'), $mug->list('variants')));
        self::assertSame('gid://lightspeed/Order/701', $reader->node(Kind::Order, 'gid://lightspeed/Order/701')?->id());
        self::assertNull($reader->node(Kind::Product, 'gid://lightspeed/Product/999'), 'a product Lightspeed no longer has is null');
        self::assertNull($reader->node(Kind::Customer, 'gid://lightspeed/Customer/999'));
        self::assertNull($reader->node(Kind::Article, 'gid://lightspeed/Article/999'));
    }

    public function testKindsLightspeedDoesNotHaveAreRefused(): void
    {
        $reader = $this->reader(self::transport('v1'));

        try {
            $reader->node(Kind::Menu, 'gid://lightspeed/Menu/1');
            self::fail('Lightspeed has no menus to read');
        } catch (SourceError) {
            $this->expectException(SourceError::class);
            $reader->page(Kind::Menu, null, null, '');
        }
    }

    public function testAShopWithoutALanguageOrKeySaysSo(): void
    {
        try {
            LightspeedReader::connect(self::API_BASE, 'xx', self::KEY, self::SECRET, new ScriptedTransport(), $this->sleeper);
            self::fail('a shop language is required');
        } catch (SourceError $e) {
            self::assertStringContainsString('shop language code', $e->getMessage());
        }
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Lightspeed eCom is not configured: enter the API key and its secret.');
        LightspeedReader::connect(self::API_BASE, self::LANGUAGE, self::KEY, '', new ScriptedTransport(), $this->sleeper);
    }

    public function testARejectedKeyIsNotRetried(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('lightspeed', 'v1'), [new HttpResponse(401, '{}')]);

        try {
            $this->reader($transport)->shop();
            self::fail('a rejected key is an error');
        } catch (CredentialsRejected $e) {
            self::assertStringContainsString('Lightspeed eCom rejected the credentials', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
        self::assertCount(1, $transport->requests, 'a static key has nothing to renew');
    }

    public function testRequestsArePacedUnderTheHourlyBucket(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $reader->page(Kind::Collection, null, null, '');
        $reader->page(Kind::Collection, null, null, '');

        self::assertCount(1, $this->sleeper->waits);
        self::assertEqualsWithDelta(3_600 / 3_000, $this->sleeper->waits[0], 1e-6, '3,000 requests an hour: one every 1.2 seconds');
    }

    public function testATooManyRequestsAnswerIsRetried(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('lightspeed', 'v1'), [new HttpResponse(429, '{}')]);

        $page = $this->reader($transport)->page(Kind::Collection, null, null, '');

        self::assertCount(2, $page->nodes);
        self::assertCount(2, $transport->requests);
        self::assertEqualsWithDelta(3_600 / 3_000, array_sum($this->sleeper->waits), 1e-6, 'the 1 s backoff, then the rest of the 1.2 s pacing interval');
    }

    /** @return list<Node> every node of a kind, following the cursor to the end */
    private function all(LightspeedReader $reader, Kind $kind, ?DateTimeImmutable $since = null): array
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
        return new ScriptedTransport(new FixtureResponder('lightspeed', $state));
    }

    private function reader(ScriptedTransport $transport): LightspeedReader
    {
        return LightspeedReader::connect(self::API_BASE, self::LANGUAGE, self::KEY, self::SECRET, $transport, $this->sleeper);
    }
}
