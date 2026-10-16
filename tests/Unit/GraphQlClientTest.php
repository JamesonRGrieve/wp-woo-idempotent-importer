<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Http\RetryPolicy;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\RetryLater;
use IdempotentImporter\Source\Shopify\ClientCredentialsAuthorizer;
use IdempotentImporter\Source\Shopify\Connection;
use IdempotentImporter\Source\Shopify\GraphQlClient;
use IdempotentImporter\Source\Shopify\QueryLibrary;
use IdempotentImporter\Source\Shopify\ShopifyTokenAuthorizer;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class GraphQlClientTest extends TestCase
{
    private const SHOP = '{"data":{"shop":{"id":"gid://shopify/Shop/1"}}}';

    public function testSendsTheOperationWithTheTokenHeader(): void
    {
        $transport = new ScriptedTransport(null, [new HttpResponse(200, self::SHOP)]);
        $data = $this->client($transport)->query('Shop');

        self::assertSame('gid://shopify/Shop/1', $data->requiredChild('shop')->id());
        self::assertSame('https://fixture.myshopify.com/admin/api/2026-10/graphql.json', $transport->requests[0]['url']);
        self::assertSame('secret-token', $transport->requests[0]['headers']['X-Shopify-Access-Token']);
        self::assertSame('Shop', $transport->operation(0));
        self::assertStringContainsString('query Shop', $transport->document(0));
    }

    public function testWaitsOutTheCostThrottle(): void
    {
        $throttled = json_encode([
            'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
            'extensions' => ['cost' => ['requestedQueryCost' => 500, 'throttleStatus' => ['currentlyAvailable' => 100, 'restoreRate' => 100]]],
        ], JSON_THROW_ON_ERROR);
        $sleeper = new RecordingSleeper();
        $transport = new ScriptedTransport(null, [new HttpResponse(200, $throttled), new HttpResponse(200, self::SHOP)]);

        $this->client($transport, $sleeper)->query('Shop');

        self::assertSame([4.0], $sleeper->waits);
        self::assertCount(2, $transport->requests);
    }

    public function testRetriesServerErrorsWithBackoffAndHonoursRetryAfter(): void
    {
        $sleeper = new RecordingSleeper();
        $transport = new ScriptedTransport(null, [
            new HttpResponse(502, ''),
            new HttpResponse(429, '', '7'),
            new HttpResponse(200, self::SHOP),
        ]);

        $this->client($transport, $sleeper)->query('Shop');

        self::assertSame([1.0, 7.0], $sleeper->waits);
    }

    public function testPausesTheRunAfterMaxAttempts(): void
    {
        $responses = array_fill(0, RetryPolicy::MAX_ATTEMPTS, new HttpResponse(503, ''));

        $this->expectException(RetryLater::class);
        $this->client(new ScriptedTransport(null, $responses))->query('Shop');
    }

    public function testWaitsForTheCostBucketBeforeTheNextQueryInsteadOfRunningIntoTheThrottle(): void
    {
        $low = json_encode([
            'data' => ['shop' => ['id' => 'gid://shopify/Shop/1']],
            'extensions' => ['cost' => ['requestedQueryCost' => 300, 'throttleStatus' => ['currentlyAvailable' => 100, 'restoreRate' => 50]]],
        ], JSON_THROW_ON_ERROR);
        $sleeper = new RecordingSleeper();
        $transport = new ScriptedTransport(null, [new HttpResponse(200, $low), new HttpResponse(200, self::SHOP)]);
        $client = $this->client($transport, $sleeper);

        $client->query('Shop');
        $client->query('Shop');

        self::assertCount(1, $sleeper->waits);
        self::assertEqualsWithDelta(4.0, $sleeper->waits[0], 1e-6, '(300 − 100) / 50 a second');
        self::assertCount(2, $transport->requests, 'no throttled request was sent');
    }

    public function testARejectedTokenStopsTheRun(): void
    {
        $this->expectException(CredentialsRejected::class);
        $this->client(new ScriptedTransport(null, [new HttpResponse(403, '')]))->query('Shop');
    }

    public function testPartialDataWithErrorsIsRejected(): void
    {
        $body = '{"data":{"shop":{"id":"x"}},"errors":[{"message":"Access denied for orders field."}]}';

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Access denied for orders field.');
        $this->client(new ScriptedTransport(null, [new HttpResponse(200, $body)]))->query('Shop');
    }

    public function testRejectedTokenIsReportedWithoutTheToken(): void
    {
        try {
            $this->client(new ScriptedTransport(null, [new HttpResponse(401, '')]))->query('Shop');
            self::fail('Expected a SourceError.');
        } catch (SourceError $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
            self::assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    public function testConnectionValidatesTheStoreDomain(): void
    {
        $this->expectException(SourceError::class);
        Connection::forStore('example.com');
    }

    public function testTheTokenEndpointIsOnTheStoresHostAndFollowsATestEndpoint(): void
    {
        self::assertSame('https://fixture.myshopify.com/admin/oauth/access_token', Connection::forStore('Fixture.myshopify.com')->tokenUrl());
        self::assertSame('http://shopify-fake:8080/admin/oauth/access_token', (new Connection('http://shopify-fake:8080/admin/api/graphql.json'))->tokenUrl());
    }

    public function testClientCredentialsAreExchangedForATokenOnceAndRenewedWhenShopifyRejectsIt(): void
    {
        $transport = new ScriptedTransport(null, [
            new HttpResponse(200, '{"access_token":"granted-1","scope":"write_products","expires_in":86399}'),
            new HttpResponse(200, self::SHOP),
            new HttpResponse(200, self::SHOP),
            new HttpResponse(401, '{"errors":"[API] Invalid API key or access token"}'),
            new HttpResponse(200, '{"access_token":"granted-2","expires_in":86399}'),
            new HttpResponse(200, self::SHOP),
        ]);
        $authorizer = new ClientCredentialsAuthorizer('https://fixture.myshopify.com/admin/oauth/access_token', 'client-id', 'client-secret', $transport);
        $client = new GraphQlClient(Connection::forStore('fixture.myshopify.com'), QueryLibrary::bundled(), $authorizer, $transport, new RecordingSleeper());

        $client->query('Shop');
        $client->query('Shop');
        $client->query('Shop');

        self::assertSame('https://fixture.myshopify.com/admin/oauth/access_token', $transport->requests[0]['url']);
        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame('grant_type=client_credentials&client_id=client-id&client_secret=client-secret', $transport->requests[0]['body']);
        self::assertSame('application/x-www-form-urlencoded', $transport->requests[0]['headers']['Content-Type']);
        self::assertSame('granted-1', $transport->requests[1]['headers']['X-Shopify-Access-Token']);
        self::assertSame('granted-1', $transport->requests[2]['headers']['X-Shopify-Access-Token'], 'the token is kept between queries');
        self::assertSame('granted-2', $transport->requests[5]['headers']['X-Shopify-Access-Token'], 'a rejected token is replaced once');
        self::assertCount(6, $transport->requests);
    }

    public function testARefusedGrantStopsTheRunWithShopifysReasonButNoSecret(): void
    {
        $transport = new ScriptedTransport(null, [new HttpResponse(400, '{"error":"app_not_installed","error_description":"The application is not installed on this shop."}')]);
        $authorizer = new ClientCredentialsAuthorizer('https://fixture.myshopify.com/admin/oauth/access_token', 'client-id', 'client-secret', $transport);

        try {
            $authorizer->headers();
            self::fail('expected CredentialsRejected');
        } catch (CredentialsRejected $e) {
            self::assertStringContainsString('HTTP 400: app_not_installed', $e->getMessage());
            self::assertStringNotContainsString('client-secret', $e->getMessage());
        }
    }

    public function testABusyTokenEndpointPausesTheRun(): void
    {
        $transport = new ScriptedTransport(null, [new HttpResponse(503, '')]);

        $this->expectException(RetryLater::class);
        (new ClientCredentialsAuthorizer('https://fixture.myshopify.com/admin/oauth/access_token', 'client-id', 'client-secret', $transport))->headers();
    }

    public function testAGrantWithoutATokenIsAnError(): void
    {
        $transport = new ScriptedTransport(null, [new HttpResponse(200, '{"scope":"write_products"}')]);

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Shopify issued no access token.');
        (new ClientCredentialsAuthorizer('https://fixture.myshopify.com/admin/oauth/access_token', 'client-id', 'client-secret', $transport))->headers();
    }

    public function testMissingCredentialsAreAConfigurationError(): void
    {
        try {
            new ClientCredentialsAuthorizer('https://fixture.myshopify.com/admin/oauth/access_token', 'client-id', '', new ScriptedTransport());
            self::fail('a missing secret is an error');
        } catch (SourceError $e) {
            self::assertStringContainsString("enter the app's client ID and client secret", $e->getMessage());
        }
        $this->expectException(SourceError::class);
        new ShopifyTokenAuthorizer('');
    }

    public function testALegacyTokenHasNothingToRenew(): void
    {
        $authorizer = new ShopifyTokenAuthorizer('legacy-token');

        self::assertSame(['X-Shopify-Access-Token' => 'legacy-token'], $authorizer->headers());
        self::assertFalse($authorizer->renew());
    }

    private function client(ScriptedTransport $transport, ?RecordingSleeper $sleeper = null): GraphQlClient
    {
        return new GraphQlClient(
            Connection::forStore('Fixture.myshopify.com'),
            QueryLibrary::bundled(),
            new ShopifyTokenAuthorizer('secret-token'),
            $transport,
            $sleeper ?? new RecordingSleeper(),
        );
    }
}
