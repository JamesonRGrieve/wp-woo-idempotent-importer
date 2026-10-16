<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Http\RetryPolicy;
use IdempotentImporter\Source\Shopify\Connection;
use IdempotentImporter\Source\Shopify\GraphQlClient;
use IdempotentImporter\Source\Shopify\QueryLibrary;
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

    public function testGivesUpAfterMaxAttempts(): void
    {
        $responses = array_fill(0, RetryPolicy::MAX_ATTEMPTS, new HttpResponse(503, ''));

        $this->expectException(SourceError::class);
        $this->client(new ScriptedTransport(null, $responses))->query('Shop');
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
        Connection::forStore('example.com', 'token');
    }

    private function client(ScriptedTransport $transport, ?RecordingSleeper $sleeper = null): GraphQlClient
    {
        return new GraphQlClient(
            Connection::forStore('Fixture.myshopify.com', 'secret-token'),
            QueryLibrary::bundled(),
            $transport,
            $sleeper ?? new RecordingSleeper(),
        );
    }
}
