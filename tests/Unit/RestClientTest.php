<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Http\RateLimit;
use IdempotentImporter\Http\ResetFormat;
use IdempotentImporter\Http\RetryPolicy;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\RetryLater;
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

    public function testASourcesRateLimitCooldownIsWaitedOutWhenItSendsNoHint(): void
    {
        $sleeper = new RecordingSleeper();
        $transport = new ScriptedTransport(null, [
            new HttpResponse(429, ''),
            new HttpResponse(429, '', '5'),
            new HttpResponse(503, ''),
            new HttpResponse(200, '{}'),
        ]);

        (new RestClient('Test', 'https://api.test', new CountingAuthorizer(false), $transport, $sleeper, new RateLimit(cooldownSeconds: 20.0)))->get('/store');

        self::assertSame([20.0, 5.0, RetryPolicy::BACKOFF_BASE_SECONDS * 4], $sleeper->waits, 'the cool-down for a bare 429, a Retry-After first, backoff for a server error');
    }

    public function testASourceThatKeepsPushingBackPausesTheRunInsteadOfFailing(): void
    {
        $sleeper = new RecordingSleeper();
        $transport = new ScriptedTransport(null, array_fill(0, RetryPolicy::MAX_ATTEMPTS, new HttpResponse(503, '')));

        try {
            (new RestClient('Test', 'https://api.test', new CountingAuthorizer(false), $transport, $sleeper))->get('/store');
            self::fail('expected RetryLater');
        } catch (RetryLater $e) {
            self::assertSame(RetryPolicy::MAX_BACKOFF_SECONDS, $e->seconds);
            self::assertStringContainsString('/store: Test is rate limiting or failing (HTTP 503, attempt 6)', $e->getMessage());
        }
        self::assertSame([1.0, 2.0, 4.0, 8.0, 16.0], $sleeper->waits, 'exponential backoff between the attempts');
        self::assertCount(RetryPolicy::MAX_ATTEMPTS, $transport->requests);
    }

    public function testAWaitTooLongToSitOutInsideARequestPausesTheRunAtOnce(): void
    {
        $sleeper = new RecordingSleeper();
        $transport = new ScriptedTransport(null, [new HttpResponse(429, '', '3600')]);

        try {
            (new RestClient('Test', 'https://api.test', new CountingAuthorizer(false), $transport, $sleeper))->get('/store');
            self::fail('expected RetryLater');
        } catch (RetryLater $e) {
            self::assertSame(3600.0, $e->seconds);
        }
        self::assertSame([], $sleeper->waits);
        self::assertCount(1, $transport->requests, 'no request is sent into a long rate-limit window');
    }

    public function testRejectedCredentialsAreTheirOwnErrorSoTheRunStops(): void
    {
        foreach ([401, 403] as $status) {
            try {
                $this->client(new ScriptedTransport(null, [new HttpResponse($status, '{}')]))->get('/store');
                self::fail("expected CredentialsRejected for {$status}");
            } catch (CredentialsRejected $e) {
                self::assertStringContainsString("HTTP {$status}", $e->getMessage());
            }
        }
    }

    public function testRequestsArePacedUnderTheSourcesRate(): void
    {
        $sleeper = new RecordingSleeper();
        $transport = new ScriptedTransport(null, array_fill(0, 3, new HttpResponse(200, '{}')));
        $client = new RestClient('Test', 'https://api.test', new CountingAuthorizer(false), $transport, $sleeper, new RateLimit(requestsPerSecond: 4.0));

        $client->get('/a');
        $client->get('/b');
        $sleeper->advance(1.0);
        $client->get('/c');

        self::assertCount(1, $sleeper->waits, 'only the request sent too soon waits');
        self::assertEqualsWithDelta(0.25, $sleeper->waits[0], 1e-6);
    }

    public function testASpentWindowIsWaitedOutBeforeTheNextRequest(): void
    {
        $sleeper = new RecordingSleeper();
        $limit = new RateLimit(remainingHeader: 'X-Left', resetHeader: 'X-Reset-Ms', resetFormat: ResetFormat::MillisecondsFromNow);
        $transport = new ScriptedTransport(null, [
            new HttpResponse(200, '{}', null, null, ['x-left' => '5', 'x-reset-ms' => '9000']),
            new HttpResponse(200, '{}', null, null, ['x-left' => '1', 'x-reset-ms' => '2500']),
            new HttpResponse(200, '{}'),
        ]);
        $client = new RestClient('Test', 'https://api.test', new CountingAuthorizer(false), $transport, $sleeper, $limit);

        $client->get('/a');
        $client->get('/b');
        $client->get('/c');

        self::assertCount(1, $sleeper->waits, 'plenty left: no wait; down to the reserve: wait for the reset');
        self::assertEqualsWithDelta(2.5, $sleeper->waits[0], 1e-6);
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
