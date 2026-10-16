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
 * it renews once if Shopify rejects it). Every operation's page sizes come from PageSizes: a query Shopify
 * refuses as too costly (MAX_COST_EXCEEDED) is resent at once with smaller pages, without using up a retry or
 * pausing the run. Waits out Shopify's cost-based throttle and retries 429/5xx with backoff; when Shopify
 * keeps pushing back it is a RetryLater (the run pauses), and a rejected token is CredentialsRejected (the
 * run stops). Any other GraphQL error (including partial data) is a SourceError (AccessDenied for a missing
 * scope), so a response is either complete or rejected. Errors never include the access token.
 */
final class GraphQlClient
{
    public const REQUEST_TIMEOUT_SECONDS = 60;
    public const MIN_THROTTLE_WAIT_SECONDS = 1.0;

    private const HTTP_OK = 200;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_FORBIDDEN = 403;
    private const THROTTLED = 'THROTTLED';
    private const MAX_COST_EXCEEDED = 'MAX_COST_EXCEEDED';
    private const ACCESS_DENIED = 'ACCESS_DENIED';
    /** The refusal's wording, read when its extensions don't carry the numbers. */
    private const MAX_COST_PATTERN = '/Query cost is (\d+), which exceeds the single query max cost limit \((\d+)\)/';

    private float $nextQueryAt = 0.0;

    private readonly PageSizes $pageSizes;

    public function __construct(
        private readonly Connection $connection,
        private readonly QueryLibrary $library,
        private readonly RequestAuthorizer $authorizer,
        private readonly HttpTransport $transport,
        private readonly Sleeper $sleeper,
    ) {
        $this->pageSizes = new PageSizes($library);
    }

    /** @param array<string, mixed> $variables */
    public function query(string $operation, array $variables = []): Node
    {
        $renewed = false;
        $attempt = 1;
        while (true) {
            $this->waitForBucket($operation);
            $response = $this->send($operation, $variables);
            if ($response->status === self::HTTP_UNAUTHORIZED && !$renewed && $this->authorizer->renew()) {
                $renewed = true;

                continue;
            }
            $wait = $this->retryWait($response, $attempt);
            if ($wait === null) {
                $refusal = self::costRefusal($response);
                if ($refusal !== null) {
                    $this->pageSizes->refused($operation, ...$refusal);

                    continue;
                }
                $node = $this->data($response, $operation);
                $this->noteCost($operation, $response);

                return $node;
            }
            if (!RetryPolicy::waitsInline($wait, $attempt)) {
                throw new RetryLater($wait, "{$operation}: Shopify is throttling or failing (HTTP {$response->status}, attempt {$attempt}).");
            }
            $this->sleeper->sleep($wait);
            ++$attempt;
        }
    }

    /** @param array<string, mixed> $variables */
    private function send(string $operation, array $variables): HttpResponse
    {
        $body = json_encode(
            [
                'query' => $this->library->document($operation),
                'variables' => (object) [...$variables, ...$this->pageSizes->for($operation)],
                'operationName' => $operation,
            ],
            JSON_THROW_ON_ERROR,
        );
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json', ...$this->authorizer->headers()];

        return $this->transport->post($this->connection->endpoint, $headers, $body, self::REQUEST_TIMEOUT_SECONDS);
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

    /** Records the response's cost: when the bucket can take the next query, and room to grow the page sizes. */
    private function noteCost(string $operation, HttpResponse $response): void
    {
        $decoded = self::decode($response->body);
        $this->nextQueryAt = $this->sleeper->now() + (self::bucketShortfall($decoded) ?? 0.0);
        $requested = self::cost($decoded)['requestedQueryCost'] ?? null;
        if (is_numeric($requested)) {
            $this->pageSizes->succeeded($operation, (int) $requested);
        }
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

        return self::hasErrorCode($decoded, self::THROTTLED) ? self::throttleWait($decoded) : null;
    }

    /**
     * The requested cost and limit when Shopify refused the query as too costly to run at all, else null.
     *
     * @return array{int, int}|null
     */
    private static function costRefusal(HttpResponse $response): ?array
    {
        if ($response->status !== self::HTTP_OK) {
            return null;
        }
        foreach (self::errors(self::decode($response->body)) as $error) {
            $extensions = is_array($error['extensions'] ?? null) ? $error['extensions'] : [];
            if (($extensions['code'] ?? null) !== self::MAX_COST_EXCEEDED) {
                continue;
            }
            if (is_int($extensions['cost'] ?? null) && is_int($extensions['maxCost'] ?? null)) {
                return [$extensions['cost'], $extensions['maxCost']];
            }
            $message = is_string($error['message'] ?? null) ? $error['message'] : '';
            if (preg_match(self::MAX_COST_PATTERN, $message, $matches) === 1) {
                return [(int) $matches[1], (int) $matches[2]];
            }

            throw new SourceError('Shopify refused a query as too costly without saying its cost.');
        }

        return null;
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
            $message = "{$operation}: " . self::errorSummary($decoded['errors']);

            throw self::onlyAccessDenied($decoded) ? new AccessDenied($message) : new SourceError($message);
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

    /**
     * @param array<array-key, mixed> $decoded
     *
     * @return list<array<array-key, mixed>>
     */
    private static function errors(array $decoded): array
    {
        $errors = $decoded['errors'] ?? null;

        return is_array($errors) ? array_values(array_filter($errors, is_array(...))) : [];
    }

    /** @param array<array-key, mixed> $error */
    private static function code(array $error): mixed
    {
        return is_array($error['extensions'] ?? null) ? $error['extensions']['code'] ?? null : null;
    }

    /** @param array<array-key, mixed> $decoded */
    private static function hasErrorCode(array $decoded, string $code): bool
    {
        foreach (self::errors($decoded) as $error) {
            if (self::code($error) === $code) {
                return true;
            }
        }

        return false;
    }

    /** @param array<array-key, mixed> $decoded */
    private static function onlyAccessDenied(array $decoded): bool
    {
        $errors = self::errors($decoded);
        foreach ($errors as $error) {
            if (self::code($error) !== self::ACCESS_DENIED) {
                return false;
            }
        }

        return $errors !== [];
    }

    /** @param array<array-key, mixed> $decoded */
    private static function throttleWait(array $decoded): float
    {
        return max(self::MIN_THROTTLE_WAIT_SECONDS, self::bucketShortfall($decoded) ?? self::MIN_THROTTLE_WAIT_SECONDS);
    }

    /**
     * The response's `extensions.cost` (requested and actual cost, throttle status), or [] when absent.
     *
     * @param array<array-key, mixed> $decoded
     *
     * @return array<array-key, mixed>
     */
    private static function cost(array $decoded): array
    {
        return is_array($decoded['extensions'] ?? null) && is_array($decoded['extensions']['cost'] ?? null)
            ? $decoded['extensions']['cost'] : [];
    }

    /**
     * How long until the bucket holds the query's cost again: (requested − available) / restore rate, 0 when
     * it already does; null when the response doesn't report the bucket.
     *
     * @param array<array-key, mixed> $decoded
     */
    private static function bucketShortfall(array $decoded): ?float
    {
        $cost = self::cost($decoded);
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
