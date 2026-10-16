<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RetryPolicy;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use JsonException;

/**
 * GETs from Etsy's Open API v3 as the connected shop owner: the API key on every request, a bearer token
 * refreshed once per client (and once more if Etsy rejects it), rate limits and server errors retried
 * through RetryPolicy. Errors never include credentials.
 */
final class EtsyClient
{
    public const REQUEST_TIMEOUT_SECONDS = 60;

    private const HTTP_OK = 200;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_FORBIDDEN = 403;
    private const HTTP_NOT_FOUND = 404;
    /** Etsy access tokens are `<user id>.<token>`. */
    private const TOKEN_USER_SEPARATOR = '.';

    private ?string $accessToken = null;

    public function __construct(
        private readonly EtsyConnection $connection,
        private readonly OAuth $oauth,
        private readonly HttpTransport $transport,
        private readonly Sleeper $sleeper,
    ) {}

    /**
     * One resource. Array query values are sent as repeated keys (OpenAPI's form/explode default).
     *
     * @param array<string, scalar|list<string>> $query
     *
     * @return Node|null null when Etsy has no such resource (404)
     */
    public function get(string $path, array $query = []): ?Node
    {
        $url = $this->connection->apiBase . $path . self::queryString($query);
        $reauthorised = false;
        for ($attempt = 1; ; ++$attempt) {
            $response = $this->transport->get($url, $this->headers(), self::REQUEST_TIMEOUT_SECONDS);
            if ($response->status === self::HTTP_UNAUTHORIZED && !$reauthorised) {
                $this->accessToken = null;
                $reauthorised = true;
                continue;
            }
            $wait = RetryPolicy::wait($response, $attempt);
            if ($wait === null) {
                return $this->node($response, $path);
            }
            if ($attempt >= RetryPolicy::MAX_ATTEMPTS) {
                throw new SourceError("{$path}: Etsy kept rate limiting or failing after {$attempt} attempts (HTTP {$response->status}).");
            }
            $this->sleeper->sleep($wait);
        }
    }

    /** The connected shop owner's Etsy user id, from the access token. */
    public function userId(): int
    {
        $prefix = strstr($this->accessToken(), self::TOKEN_USER_SEPARATOR, true);
        if ($prefix === false || !ctype_digit($prefix)) {
            throw new SourceError('Etsy returned an access token without a user id.');
        }

        return (int) $prefix;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'x-api-key' => $this->connection->apiKey(),
            'Authorization' => 'Bearer ' . $this->accessToken(),
        ];
    }

    private function accessToken(): string
    {
        return $this->accessToken ??= $this->oauth->refresh();
    }

    private function node(HttpResponse $response, string $path): ?Node
    {
        if ($response->status === self::HTTP_NOT_FOUND) {
            return null;
        }
        if ($response->status === self::HTTP_UNAUTHORIZED || $response->status === self::HTTP_FORBIDDEN) {
            throw new SourceError("{$path}: Etsy rejected the credentials or the app lacks a required scope (HTTP {$response->status}).");
        }
        if ($response->status !== self::HTTP_OK) {
            throw new SourceError("{$path}: Etsy returned HTTP {$response->status}.");
        }
        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SourceError("{$path}: Etsy returned a response that isn't JSON.");
        }
        if (!is_array($decoded)) {
            throw new SourceError("{$path}: Etsy returned no object.");
        }

        return new Node($decoded, $path);
    }

    /** @param array<string, scalar|list<string>> $query */
    private static function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $text = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
                $pairs[] = rawurlencode($name) . '=' . rawurlencode($text);
            }
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }
}
