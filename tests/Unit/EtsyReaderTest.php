<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Etsy\EtsyClient;
use IdempotentImporter\Source\Etsy\EtsyConnection;
use IdempotentImporter\Source\Etsy\EtsyReader;
use IdempotentImporter\Source\Etsy\OAuth;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\EtsyResponder;
use IdempotentImporter\Tests\Support\InMemoryRefreshTokens;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class EtsyReaderTest extends TestCase
{
    private const API_BASE = 'https://openapi.etsy.test';

    private InMemoryRefreshTokens $tokens;
    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->tokens = new InMemoryRefreshTokens('stored-refresh-token');
        $this->sleeper = new RecordingSleeper();
    }

    public function testReadsTheOwnersShopWithItsReturnPoliciesAsTheOwner(): void
    {
        $transport = new ScriptedTransport(new EtsyResponder('v1'));

        $shop = $this->reader($transport)->shop();

        self::assertSame('gid://etsy/Shop/5001', $shop->id());
        self::assertSame(41, $shop->list('return_policies')[0]->int('return_policy_id'));
        self::assertCount(3, $transport->requests, 'one token refresh, the owner shop once, the return policies');
        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame(self::API_BASE . '/v3/public/oauth/token', $transport->requests[0]['url']);
        self::assertSame('grant_type=refresh_token&client_id=keystring&refresh_token=stored-refresh-token', $transport->requests[0]['body']);
        self::assertSame(EtsyResponder::REFRESH_TOKEN, $this->tokens->get(), 'the rotated refresh token is kept');
        self::assertSame(self::API_BASE . '/v3/application/users/7001/shops', $transport->requests[1]['url']);
        self::assertSame('keystring:shared-secret', $transport->requests[1]['headers']['x-api-key']);
        self::assertSame('Bearer ' . EtsyResponder::ACCESS_TOKEN, $transport->requests[1]['headers']['Authorization']);
    }

    public function testListingsArePagedThroughEveryStateWithTheirAssociations(): void
    {
        $transport = new ScriptedTransport(new EtsyResponder('v1'));

        $ids = array_map(static fn(Node $node): string => $node->id(), $this->all($this->reader($transport), Kind::Product));

        self::assertSame(['gid://etsy/Listing/601', 'gid://etsy/Listing/602', 'gid://etsy/Listing/604', 'gid://etsy/Listing/603'], $ids);
        $listingUrls = array_values(array_filter(array_column($transport->requests, 'url'), static fn(string $url): bool => str_contains($url, '/listings?')));
        self::assertCount(count(EtsyReader::LISTING_STATES), $listingUrls);
        self::assertStringContainsString('state=active&includes=Images&includes=Inventory&includes=Videos', $listingUrls[0]);
        self::assertStringContainsString('state=expired', $listingUrls[4]);
    }

    public function testBuyersComeFromReceiptsOncePerPage(): void
    {
        $buyers = $this->all($this->reader(new ScriptedTransport(new EtsyResponder('v2'))), Kind::Customer);

        self::assertSame(['gid://etsy/User/9001', 'gid://etsy/User/9002'], array_map(static fn(Node $node): string => $node->id(), $buyers));
        self::assertSame('Ada@Example.com', $buyers[0]->string('buyer_email'));
    }

    public function testIncrementalKindsAskOnlyForWhatChanged(): void
    {
        $transport = new ScriptedTransport(new EtsyResponder('v1'));
        $reader = $this->reader($transport);
        $since = new DateTimeImmutable('2026-09-01T00:00:00Z');

        $reader->page(Kind::Order, null, $since, '');
        $reader->page(Kind::Review, null, $since, '');

        $urls = array_column($transport->requests, 'url');
        self::assertStringContainsString('sort_on=created&sort_order=asc&min_last_modified=' . $since->getTimestamp(), $urls[2]);
        self::assertStringContainsString('min_created=' . $since->getTimestamp(), $urls[3]);
        self::assertTrue($reader->isIncremental(Kind::Order));
        self::assertFalse($reader->isIncremental(Kind::Product), 'Etsy cannot filter listings by update time');
    }

    public function testAnObjectEtsyNoLongerHasIsNull(): void
    {
        self::assertNull($this->reader(new ScriptedTransport(new EtsyResponder('v1')))->node(Kind::Product, 'gid://etsy/Listing/999'));
    }

    public function testARejectedTokenIsRefreshedOnceAndTheRequestRetried(): void
    {
        $transport = new ScriptedTransport(new EtsyResponder('v1'), [self::token(), new HttpResponse(401, '{}')]);

        self::assertSame('gid://etsy/Shop/5001', $this->reader($transport)->shop()->id());
        self::assertSame(['POST', 'GET', 'POST', 'GET', 'GET'], array_column($transport->requests, 'method'));
    }

    public function testRateLimitsAreWaitedOut(): void
    {
        $transport = new ScriptedTransport(new EtsyResponder('v1'), [self::token(), new HttpResponse(429, '{}', '2')]);

        $this->reader($transport)->page(Kind::Collection, null, null, '');

        self::assertSame([2.0], $this->sleeper->waits);
    }

    public function testAShopThatIsNotConnectedSaysSo(): void
    {
        $this->tokens = new InMemoryRefreshTokens();

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Etsy is not connected');
        $this->reader(new ScriptedTransport(new EtsyResponder('v1')))->shop();
    }

    public function testKindsEtsyDoesNotHaveAreRefused(): void
    {
        $this->expectException(SourceError::class);
        $this->reader(new ScriptedTransport(new EtsyResponder('v1')))->page(Kind::Menu, null, null, '');
    }

    public function testTheAuthorizationUrlAsksForReadScopesWithPkce(): void
    {
        $url = $this->oauth(new ScriptedTransport())->authorizationUrl('https://shop.test/callback', 'state-1', 'verifier-1');

        self::assertStringStartsWith(EtsyConnection::AUTHORIZE_URL . '?response_type=code&client_id=keystring&redirect_uri=https%3A%2F%2Fshop.test%2Fcallback', $url);
        self::assertStringContainsString('scope=listings_r%20transactions_r%20shops_r%20email_r&state=state-1', $url);
        self::assertStringContainsString('&code_challenge_method=S256', $url);
    }

    /** @return list<Node> every node of a kind, following the cursor to the end */
    private function all(EtsyReader $reader, Kind $kind): array
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

    private static function token(): HttpResponse
    {
        return new HttpResponse(200, (string) json_encode(['access_token' => EtsyResponder::ACCESS_TOKEN, 'refresh_token' => 'rotated']));
    }

    private function oauth(ScriptedTransport $transport): OAuth
    {
        return new OAuth(new EtsyConnection(self::API_BASE, 'keystring', 'shared-secret'), $transport, $this->tokens);
    }

    private function reader(ScriptedTransport $transport): EtsyReader
    {
        return new EtsyReader(new EtsyClient(new EtsyConnection(self::API_BASE, 'keystring', 'shared-secret'), $this->oauth($transport), $transport, $this->sleeper));
    }
}
