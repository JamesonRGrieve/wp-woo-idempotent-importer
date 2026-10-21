<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\RetryLater;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\Webflow\Mapping\Fields;
use IdempotentImporter\Source\Webflow\WebflowReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class WebflowReaderTest extends TestCase
{
    private const API = 'https://api.webflow.test/v2';
    private const SITE = '64f1a2b3c4d5e6f708192a3b';
    private const BLOG = '64f1a2b3c4d5e6f708192b04';
    private const TOKEN = 'wf-site-token';

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
    }

    public function testReadsTheSiteWithItsEcommerceSettingsAndTheToken(): void
    {
        $transport = self::transport('v1');

        $shop = $this->reader($transport)->shop();

        self::assertSame('gid://webflow/Shop/' . self::SITE, $shop->id());
        self::assertSame('Fernhill Ceramics', $shop->string('displayName'));
        self::assertSame('CAD', $shop->requiredChild('ecommerce')->string('defaultCurrency'));
        self::assertSame([self::API . '/sites/' . self::SITE, self::API . '/sites/' . self::SITE . '/ecommerce/settings'], array_column($transport->requests, 'url'));
        self::assertSame('Bearer ' . self::TOKEN, $transport->requests[0]['headers']['Authorization']);
    }

    public function testASiteWithoutEcommerceHasNoCurrency(): void
    {
        $transport = new ScriptedTransport(new FixtureResponder('webflow', 'v1'), [
            new HttpResponse(200, (string) json_encode(['id' => self::SITE, 'displayName' => 'Brochure'])),
            new HttpResponse(404, '{}'),
        ]);

        self::assertNull($this->reader($transport)->shop()->child('ecommerce'));
    }

    public function testProductsArriveWithTheirSkusAndEachSkusInventory(): void
    {
        $transport = self::transport('v1');

        $products = $this->reader($transport)->page(Kind::Product, null, null, '')->nodes;

        self::assertSame(['gid://webflow/Product/64f1a2b3c4d5e6f708194d01', 'gid://webflow/Product/64f1a2b3c4d5e6f708194d02'], self::gids($products));
        $skus = $products[1]->list('skus');
        self::assertSame(['gid://webflow/Variant/64f1a2b3c4d5e6f708195e02', 'gid://webflow/Variant/64f1a2b3c4d5e6f708195e03'], self::gids($skus));
        self::assertSame('infinite', $skus[1]->requiredChild('inventory')->string('inventoryType'));
        self::assertContains(self::API . '/collections/64f1a2b3c4d5e6f708192b02/items/64f1a2b3c4d5e6f708195e01/inventory', array_column($transport->requests, 'url'), 'inventory from the SKUs collection');
    }

    public function testListsArePagedByOffsetUntilTheTotal(): void
    {
        $full = ['redirects' => array_map(static fn(int $n): array => ['id' => "r{$n}", 'fromUrl' => "/a{$n}", 'toUrl' => '/b'], range(1, WebflowReader::PAGE_SIZE)), 'pagination' => ['limit' => 100, 'offset' => 0, 'total' => 101]];
        $transport = new ScriptedTransport(new FixtureResponder('webflow', 'v1'), [new HttpResponse(200, (string) json_encode($full))]);
        $reader = $this->reader($transport);

        $first = $reader->page(Kind::UrlRedirect, null, null, '');

        self::assertCount(WebflowReader::PAGE_SIZE, $first->nodes);
        self::assertTrue($first->hasNextPage);
        self::assertSame('100', $first->endCursor);
        self::assertSame(self::API . '/sites/' . self::SITE . '/redirects?offset=0&limit=100', $transport->requests[0]['url']);
    }

    public function testCustomersAreGatheredFromEveryOrderByEmailWithTheirFirstOrder(): void
    {
        $customers = $this->reader(self::transport('v2'))->page(Kind::Customer, null, null, '');

        self::assertFalse($customers->hasNextPage);
        $emails = array_map(static fn(Node $customer): string => $customer->string('email'), $customers->nodes);
        self::assertSame(['ada.thorne@example.com', 'bo.lindqvist@example.com', 'cy.moreau@example.com'], $emails, 'one customer per email, whatever its case');
        self::assertSame(Fields::customerId('ada.thorne@example.com'), $customers->nodes[0]->id());
        self::assertSame('12 Cedar Lane', $customers->nodes[0]->requiredChild('shippingAddress')->string('line1'), "the first order's address, not the later one's");
        self::assertSame('2026-09-02T17:30:00.000Z', $customers->nodes[0]->string('firstOrderOn'));
    }

    public function testPagesSkipCollectionTemplatesAndCarryTheirDomAndImageAssets(): void
    {
        $transport = self::transport('v1');

        $pages = $this->reader($transport)->page(Kind::Page, null, null, '')->nodes;

        self::assertSame(['gid://webflow/Page/64f1a2b3c4d5e6f708197a01'], self::gids($pages), 'the journal template page is its items');
        self::assertCount(5, $pages[0]->list('dom_nodes'));
        self::assertSame('http://webflow-fake:8080/images/wf-studio.png', $pages[0]->requiredChild('assets')->requiredChild('64f1a2b3c4d5e6f708196f01')->string('url'));
    }

    public function testTheNamedBlogCollectionIsTheBlogAndTheOthersAreContentTypes(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $blogs = $reader->page(Kind::Blog, null, null, '')->nodes;
        $articles = $reader->page(Kind::Article, null, null, '')->nodes;
        $types = $reader->page(Kind::ContentType, null, null, '')->nodes;
        $items = $reader->page(Kind::ContentItem, null, null, '');
        $categories = $reader->page(Kind::Collection, null, null, '')->nodes;

        self::assertSame(['gid://webflow/Collection/' . self::BLOG], self::gids($blogs));
        self::assertCount(2, $articles);
        self::assertSame('journal', $articles[0]->string('collection_slug'));
        self::assertSame(['gid://webflow/Collection/64f1a2b3c4d5e6f708192b05'], self::gids($types), 'e-commerce collections and the blog are not content types');
        self::assertSame(['gid://webflow/Item/64f1a2b3c4d5e6f708199c01'], self::gids($items->nodes));
        self::assertFalse($items->hasNextPage);
        self::assertSame(['gid://webflow/Category/64f1a2b3c4d5e6f708193c01', 'gid://webflow/Category/64f1a2b3c4d5e6f708193c02'], self::gids($categories));
    }

    public function testWithoutABlogCollectionEveryCollectionIsAContentType(): void
    {
        $reader = WebflowReader::connect(self::API, self::SITE, '', BearerAuthorizer::token(self::TOKEN, 'Webflow'), self::transport('v1'), $this->sleeper);

        self::assertSame([], $reader->page(Kind::Blog, null, null, '')->nodes);
        self::assertSame([], $reader->page(Kind::Article, null, null, '')->nodes);
        self::assertCount(2, $reader->page(Kind::ContentType, null, null, '')->nodes);
        $first = $reader->page(Kind::ContentItem, null, null, '');
        self::assertTrue($first->hasNextPage, 'the next collection follows');
        self::assertSame('1:', $first->endCursor);
        self::assertCount(1, $reader->page(Kind::ContentItem, $first->endCursor, null, '')->nodes);
    }

    public function testReadsASingleProductOrOrder(): void
    {
        $reader = $this->reader(self::transport('v1'));

        self::assertSame('gid://webflow/Order/a3f9c1', $reader->node(Kind::Order, 'gid://webflow/Order/a3f9c1')?->id());
        self::assertCount(1, $reader->node(Kind::Product, 'gid://webflow/Product/64f1a2b3c4d5e6f708194d01')?->list('skus') ?? []);
        self::assertNull($reader->node(Kind::Order, 'gid://webflow/Order/ffffff'));
        $this->expectException(SourceError::class);
        $reader->node(Kind::Page, 'gid://webflow/Page/64f1a2b3c4d5e6f708197a01');
    }

    public function testNothingIsIncremental(): void
    {
        $reader = $this->reader(self::transport('v1'));

        foreach (Kind::SYNC_ORDER as $kind) {
            self::assertFalse($reader->isIncremental($kind), $kind->value);
        }
    }

    public function testPacesUnderTheRateLimitAndPausesTheRunWhenTheMinuteIsSpent(): void
    {
        $transport = self::transport('v1');
        $reader = $this->reader($transport);
        $reader->shop();
        self::assertSame([1.0], $this->sleeper->waits, 'one request a second (60 a minute)');

        $spent = new HttpResponse(200, (string) json_encode(['id' => self::SITE]), headers: ['x-ratelimit-remaining' => '1']);
        $reader = $this->reader(new ScriptedTransport(new FixtureResponder('webflow', 'v1'), [$spent]));
        try {
            $reader->shop();
            self::fail('the request after the last of the minute waits for the window');
        } catch (RetryLater $e) {
            self::assertGreaterThanOrEqual(59.0, $e->seconds, 'longer than a request may wait, so the run pauses');
        }
    }

    public function testARejectedTokenStopsTheRun(): void
    {
        $transport = new ScriptedTransport(null, [new HttpResponse(401, '{}')]);

        $this->expectException(CredentialsRejected::class);
        $this->reader($transport)->shop();
    }

    public function testASiteIdIsRequired(): void
    {
        $this->expectException(SourceError::class);
        WebflowReader::connect(self::API, 'not-a-site', null, BearerAuthorizer::token(self::TOKEN, 'Webflow'), self::transport('v1'), $this->sleeper);
    }

    private function reader(ScriptedTransport $transport): WebflowReader
    {
        return WebflowReader::connect(self::API, self::SITE, self::BLOG, BearerAuthorizer::token(self::TOKEN, 'Webflow'), $transport, $this->sleeper);
    }

    private static function transport(string $state): ScriptedTransport
    {
        return new ScriptedTransport(new FixtureResponder('webflow', $state));
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
}
