<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RetryPolicy;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\RetryLater;
use IdempotentImporter\Source\SourceError;
use JsonException;

/**
 * Sends the library's operations to the Admin GraphQL API, authenticated by the store's authorizer (a token
 * it renews once if Shopify rejects it). Waits out Shopify's cost-based throttle and retries 429/5xx with
 * backoff; when Shopify keeps pushing back it is a RetryLater (the run pauses), and a rejected token is
 * CredentialsRejected (the run stops). Any GraphQL error (including partial data) is a
 * SourceError, so a response is either complete or rejected. Errors never include the access token.
 */
final class GraphQlClient
{
    public const REQUEST_TIMEOUT_SECONDS = 60;
    public const MIN_THROTTLE_WAIT_SECONDS = 1.0;

    private const HTTP_OK = 200;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_FORBIDDEN = 403;
    private const THROTTLED = 'THROTTLED';

    private float $nextQueryAt = 0.0;

    public function __construct(
        private readonly Connection $connection,
        private readonly QueryLibrary $library,
        private readonly RequestAuthorizer $authorizer,
        private readonly HttpTransport $transport,
        private readonly Sleeper $sleeper,
    ) {}

    /** @param array<string, mixed> $variables */
    public function query(string $operation, array $variables = []): Node
    {
        $body = json_encode(
            ['query' => $this->library->document($operation), 'variables' => (object) $variables, 'operationName' => $operation],
            JSON_THROW_ON_ERROR,
        );
        $renewed = false;
        for ($attempt = 1; ; ++$attempt) {
            $this->waitForBucket($operation);
            $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json', ...$this->authorizer->headers()];
            $response = $this->transport->post($this->connection->endpoint, $headers, $body, self::REQUEST_TIMEOUT_SECONDS);
            if ($response->status === self::HTTP_UNAUTHORIZED && !$renewed && $this->authorizer->renew()) {
                $renewed = true;
                continue;
            }
            $wait = $this->retryWait($response, $attempt);
            if ($wait === null) {
                $node = $this->data($response, $operation);
                $this->noteBucket($response);

                return $node;
            }
            if (!RetryPolicy::waitsInline($wait, $attempt)) {
                throw new RetryLater($wait, "{$operation}: Shopify is throttling or failing (HTTP {$response->status}, attempt {$attempt}).");
            }
            $this->sleeper->sleep($wait);
        }
    }

    /**
     * Holds the next query until Shopify's cost bucket can take one as costly as the last, so a long import
     * stays under the throttle instead of running into it.
     */
    private function waitForBucket(string $operation): void
    {
        $wait = $this->nextQueryAt - $this->sleeper->now();
        if ($wait > RetryPolicy::MAX_INLINE_WAIT_SECONDS) {
            throw new RetryLater($wait, "{$operation}: Shopify's query cost budget is spent until it refills.");
        }
        if ($wait > 0) {
            $this->sleeper->sleep($wait);
        }
    }

    private function noteBucket(HttpResponse $response): void
    {
        $this->nextQueryAt = $this->sleeper->now() + (self::bucketShortfall(self::decode($response->body)) ?? 0.0);
    }

    /** Seconds to wait before retrying (HTTP retry policy, then Shopify's cost throttle), or null when final. */
    private function retryWait(HttpResponse $response, int $attempt): ?float
    {
        $wait = RetryPolicy::wait($response, $attempt);
        if ($wait !== null) {
            return $wait;
        }
        if ($response->status !== self::HTTP_OK) {
            return null;
        }
        $decoded = self::decode($response->body);

        return self::isThrottled($decoded) ? self::throttleWait($decoded) : null;
    }

    private function data(HttpResponse $response, string $operation): Node
    {
        if ($response->status === self::HTTP_UNAUTHORIZED || $response->status === self::HTTP_FORBIDDEN) {
            throw new CredentialsRejected("{$operation}: Shopify rejected the access token or the app lacks a required scope (HTTP {$response->status}).");
        }
        if ($response->status !== self::HTTP_OK) {
            throw new SourceError("{$operation}: Shopify returned HTTP {$response->status}.");
        }
        $decoded = self::decode($response->body);
        if (isset($decoded['errors'])) {
            throw new SourceError("{$operation}: " . self::errorSummary($decoded['errors']));
        }
        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            throw new SourceError("{$operation}: Shopify returned no data.");
        }

        return new Node($data, $operation);
    }

    /** @return array<array-key, mixed> */
    private static function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SourceError('Shopify returned a response that is not JSON.');
        }
        if (!is_array($decoded)) {
            throw new SourceError('Shopify returned a response that is not a JSON object.');
        }

        return $decoded;
    }

    /** @param array<array-key, mixed> $decoded */
    private static function isThrottled(array $decoded): bool
    {
        $errors = $decoded['errors'] ?? null;
        if (!is_array($errors)) {
            return false;
        }
        foreach ($errors as $error) {
            if (is_array($error) && is_array($error['extensions'] ?? null) && ($error['extensions']['code'] ?? null) === self::THROTTLED) {
                return true;
            }
        }

        return false;
    }

    /** @param array<array-key, mixed> $decoded */
    private static function throttleWait(array $decoded): float
    {
        return max(self::MIN_THROTTLE_WAIT_SECONDS, self::bucketShortfall($decoded) ?? self::MIN_THROTTLE_WAIT_SECONDS);
    }

    /**
     * How long until the bucket holds the query's cost again: (requested − available) / restore rate, 0 when
     * it already does; null when the response doesn't report the bucket.
     *
     * @param array<array-key, mixed> $decoded
     */
    private static function bucketShortfall(array $decoded): ?float
    {
        $cost = is_array($decoded['extensions'] ?? null) && is_array($decoded['extensions']['cost'] ?? null)
            ? $decoded['extensions']['cost'] : [];
        $status = is_array($cost['throttleStatus'] ?? null) ? $cost['throttleStatus'] : [];
        $requested = is_numeric($cost['requestedQueryCost'] ?? null) ? (float) $cost['requestedQueryCost'] : 0.0;
        $available = is_numeric($status['currentlyAvailable'] ?? null) ? (float) $status['currentlyAvailable'] : 0.0;
        $restoreRate = is_numeric($status['restoreRate'] ?? null) ? (float) $status['restoreRate'] : 0.0;
        if ($restoreRate <= 0.0) {
            return null;
        }

        return max(0.0, ($requested - $available) / $restoreRate);
    }

    private static function errorSummary(mixed $errors): string
    {
        if (!is_array($errors)) {
            return 'Shopify returned an error.';
        }
        $messages = [];
        foreach ($errors as $error) {
            if (is_array($error) && is_string($error['message'] ?? null)) {
                $messages[] = $error['message'];
            }
        }

        return $messages === [] ? 'Shopify returned an error.' : implode('; ', $messages);
    }
}
