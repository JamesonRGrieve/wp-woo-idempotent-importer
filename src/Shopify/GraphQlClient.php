<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Shopify;

use JsonException;

/**
 * Sends the library's operations to the Admin GraphQL API. Waits out Shopify's cost-based throttle and
 * retries 429/5xx with backoff; any GraphQL error (including partial data) is a ShopifyError, so a
 * response is either complete or rejected. Errors never include the access token.
 */
final class GraphQlClient
{
    public const REQUEST_TIMEOUT_SECONDS = 60;
    public const MAX_ATTEMPTS = 6;
    public const BACKOFF_BASE_SECONDS = 1.0;
    public const MIN_THROTTLE_WAIT_SECONDS = 1.0;

    private const HTTP_OK = 200;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_FORBIDDEN = 403;
    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_SERVER_ERROR = 500;
    private const THROTTLED = 'THROTTLED';

    public function __construct(
        private readonly Connection $connection,
        private readonly QueryLibrary $library,
        private readonly HttpTransport $transport,
        private readonly Sleeper $sleeper,
    ) {}

    /** @param array<string, mixed> $variables */
    public function query(string $operation, array $variables = []): ShopifyNode
    {
        $body = json_encode(
            ['query' => $this->library->document($operation), 'variables' => (object) $variables, 'operationName' => $operation],
            JSON_THROW_ON_ERROR,
        );
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Shopify-Access-Token' => $this->connection->accessToken,
        ];
        for ($attempt = 1; ; ++$attempt) {
            $response = $this->transport->post($this->connection->endpoint, $headers, $body, self::REQUEST_TIMEOUT_SECONDS);
            $wait = $this->retryWait($response, $attempt);
            if ($wait === null) {
                return $this->data($response, $operation);
            }
            if ($attempt >= self::MAX_ATTEMPTS) {
                throw new ShopifyError("{$operation}: Shopify kept throttling or failing after {$attempt} attempts (HTTP {$response->status}).");
            }
            $this->sleeper->sleep($wait);
        }
    }

    /** Seconds to wait before retrying, or null when the response is final. */
    private function retryWait(HttpResponse $response, int $attempt): ?float
    {
        if ($response->status === self::HTTP_TOO_MANY_REQUESTS || $response->status >= self::HTTP_SERVER_ERROR) {
            $retryAfter = $response->retryAfter !== null && is_numeric($response->retryAfter) ? (float) $response->retryAfter : null;

            return $retryAfter ?? self::BACKOFF_BASE_SECONDS * (2 ** ($attempt - 1));
        }
        if ($response->status !== self::HTTP_OK) {
            return null;
        }
        $decoded = self::decode($response->body);

        return self::isThrottled($decoded) ? self::throttleWait($decoded) : null;
    }

    private function data(HttpResponse $response, string $operation): ShopifyNode
    {
        if ($response->status === self::HTTP_UNAUTHORIZED || $response->status === self::HTTP_FORBIDDEN) {
            throw new ShopifyError("{$operation}: Shopify rejected the access token or the app lacks a required scope (HTTP {$response->status}).");
        }
        if ($response->status !== self::HTTP_OK) {
            throw new ShopifyError("{$operation}: Shopify returned HTTP {$response->status}.");
        }
        $decoded = self::decode($response->body);
        if (isset($decoded['errors'])) {
            throw new ShopifyError("{$operation}: " . self::errorSummary($decoded['errors']));
        }
        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            throw new ShopifyError("{$operation}: Shopify returned no data.");
        }

        return new ShopifyNode($data, $operation);
    }

    /** @return array<array-key, mixed> */
    private static function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ShopifyError('Shopify returned a response that is not JSON.');
        }
        if (!is_array($decoded)) {
            throw new ShopifyError('Shopify returned a response that is not a JSON object.');
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

    /**
     * How long until the bucket holds the query's cost again: (requested − available) / restore rate.
     *
     * @param array<array-key, mixed> $decoded
     */
    private static function throttleWait(array $decoded): float
    {
        $cost = is_array($decoded['extensions'] ?? null) && is_array($decoded['extensions']['cost'] ?? null)
            ? $decoded['extensions']['cost'] : [];
        $status = is_array($cost['throttleStatus'] ?? null) ? $cost['throttleStatus'] : [];
        $requested = is_numeric($cost['requestedQueryCost'] ?? null) ? (float) $cost['requestedQueryCost'] : 0.0;
        $available = is_numeric($status['currentlyAvailable'] ?? null) ? (float) $status['currentlyAvailable'] : 0.0;
        $restoreRate = is_numeric($status['restoreRate'] ?? null) ? (float) $status['restoreRate'] : 0.0;
        if ($restoreRate <= 0.0) {
            return self::MIN_THROTTLE_WAIT_SECONDS;
        }

        return max(self::MIN_THROTTLE_WAIT_SECONDS, ($requested - $available) / $restoreRate);
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
