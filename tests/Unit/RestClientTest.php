<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Http\RetryPolicy;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\CountingAuthorizer;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class RestClientTest extends TestCase
{
    public function testSendsTheSourcesAuthenticationAndRepeatsArrayQueryKeys(): void
    {
        $transport = new ScriptedTransport(null, [new HttpResponse(200, '{"id":1}')]);

        $node = $this->client($transport)->get('/products', ['include' => ['images', 'variants'], 'page' => 2, 'active' => true]);

        self::assertSame(1, $node?->int('id'));
        self::assertSame('https://api.test/products?include=images&include=variants&page=2&active=true', $transport->requests[0]['url']);
        self::assertSame('token-1', $transport->requests[0]['headers'][CountingAuthorizer::HEADER]);
        self::assertSame('application/json', $transport->requests[0]['headers']['Accept']);
    }

    public function testAListResponseIsReturnedUnderItemsAndNoContentIsAnEmptyList(): void
    {
        $client = $this->client(new ScriptedTransport(null, [new HttpResponse(200, '[{"id":1},{"id":2}]'), new HttpResponse(204, '')]));

        self::assertCount(2, $client->get('/orders')?->list(RestClient::LIST_KEY) ?? []);
        self::assertSame([], $client->get('/orders')?->list(RestClient::LIST_KEY));
    }

    public function testNotFoundIsNull(): void
    {
        self::assertNull($this->client(new ScriptedTransport(null, [new HttpResponse(404, '{}')]))->get('/products/9'));
    }

    public function testARejectedTokenIsRenewedOnceThenReported(): void
    {
        $authorizer = new CountingAuthorizer(true);
        $transport = new ScriptedTransport(null, [new HttpResponse(401, '{}'), new HttpResponse(401, '{}')]);

        try {
            $this->client($transport, $authorizer)->get('/store');
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('Test rejected the credentials', $e->getMessage());
        }
        self::assertSame(1, $authorizer->renewals);
        self::assertSame(['token-1', 'token-2'], array_map(static fn(array $request): string => $request['headers'][CountingAuthorizer::HEADER], $transport->requests));
    }

    public function testAStaticCredentialIsNotRetriedOnRejection(): void
    {
        $transport = new ScriptedTransport(null, [new HttpResponse(401, '{}')]);

        try {
            $this->client($transport)->get('/store');
            self::fail('expected a SourceError');
        } catch (SourceError) {
            self::assertCount(1, $transport->requests);
        }
    }

    public function testRateLimitsAreWaitedOut(): void
    {
        $sleeper = new RecordingSleeper();
        $transport = new ScriptedTransport(null, [new HttpResponse(429, '', '3'), new HttpResponse(200, '{}')]);

        (new RestClient('Test', 'https://api.test', new CountingAuthorizer(false), $transport, $sleeper))->get('/store');

        self::assertSame([3.0], $sleeper->waits);
    }

    public function testARateLimitResetInMillisecondsIsWaitedOutWhenThereIsNoRetryAfter(): void
    {
        $sleeper = new RecordingSleeper();
        $transport = new ScriptedTransport(null, [
            new HttpResponse(429, '', null, '1500'),
            new HttpResponse(429, '', '2', '9000'),
            new HttpResponse(503, '', null, 'soon'),
            new HttpResponse(200, '{}'),
        ]);

        (new RestClient('Test', 'https://api.test', new CountingAuthorizer(false), $transport, $sleeper))->get('/store');

        self::assertSame([1.5, 2.0, RetryPolicy::BACKOFF_BASE_SECONDS * 4], $sleeper->waits, 'reset ms, then Retry-After first, then backoff for a hint that is not a number');
    }

    public function testAQueryIsAJsonPost(): void
    {
        $transport = new ScriptedTransport(null, [new HttpResponse(200, '{"products":[]}')]);

        $this->client($transport)->query('/products/query', ['paging' => ['limit' => 100]]);

        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame('{"paging":{"limit":100}}', $transport->requests[0]['body']);
        self::assertSame('application/json', $transport->requests[0]['headers']['Content-Type']);
    }

    public function testANonJsonResponseIsAnError(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage("/store: Test returned a response that isn't JSON.");
        $this->client(new ScriptedTransport(null, [new HttpResponse(200, '<html>')]))->get('/store');
    }

    private function client(ScriptedTransport $transport, ?CountingAuthorizer $authorizer = null): RestClient
    {
        return new RestClient('Test', 'https://api.test', $authorizer ?? new CountingAuthorizer(false), $transport, new RecordingSleeper());
    }
}
