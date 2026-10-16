<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Rest;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RetryPolicy;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use JsonException;

/**
 * A source's JSON REST API, read-only: GETs (and the POST queries some APIs use for searches) with the
 * source's authentication, a token renewed once if the source rejects it, and rate limits and server
 * errors retried through RetryPolicy. A response that is a JSON list is returned under `items`; 204 is an
 * empty list; 404 is "no such resource". Errors name the source and path, never credentials.
 */
final class RestClient
{
    public const REQUEST_TIMEOUT_SECONDS = 60;
    public const LIST_KEY = 'items';

    private const HTTP_OK = 200;
    private const HTTP_NO_CONTENT = 204;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_FORBIDDEN = 403;
    private const HTTP_NOT_FOUND = 404;

    public function __construct(
        private readonly string $label,
        private readonly string $baseUrl,
        private readonly RequestAuthorizer $authorizer,
        private readonly HttpTransport $transport,
        private readonly Sleeper $sleeper,
    ) {}

    /**
     * Array query values are sent as repeated keys (OpenAPI's form/explode default).
     *
     * @param array<string, scalar|list<string>> $query
     *
     * @return Node|null null when the source has no such resource (404)
     */
    public function get(string $path, array $query = []): ?Node
    {
        $url = $this->baseUrl . $path . self::queryString($query);

        return $this->send($path, fn(array $headers): HttpResponse => $this->transport->get($url, $headers, self::REQUEST_TIMEOUT_SECONDS));
    }

    /**
     * A read-only query sent as a JSON POST (search endpoints that take their filter in the body).
     *
     * @param array<string, mixed> $body
     */
    public function query(string $path, array $body): ?Node
    {
        $json = json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->send(
            $path,
            fn(array $headers): HttpResponse => $this->transport->post($this->baseUrl . $path, ['Content-Type' => 'application/json', ...$headers], $json, self::REQUEST_TIMEOUT_SECONDS),
        );
    }

    /** @param callable(array<string, string>): HttpResponse $request */
    private function send(string $path, callable $request): ?Node
    {
        $renewed = false;
        for ($attempt = 1; ; ++$attempt) {
            $response = $request(['Accept' => 'application/json', ...$this->authorizer->headers()]);
            if ($response->status === self::HTTP_UNAUTHORIZED && !$renewed && $this->authorizer->renew()) {
                $renewed = true;
                continue;
            }
            $wait = RetryPolicy::wait($response, $attempt);
            if ($wait === null) {
                return $this->node($response, $path);
            }
            if ($attempt >= RetryPolicy::MAX_ATTEMPTS) {
                throw new SourceError("{$path}: {$this->label} kept rate limiting or failing after {$attempt} attempts (HTTP {$response->status}).");
            }
            $this->sleeper->sleep($wait);
        }
    }

    private function node(HttpResponse $response, string $path): ?Node
    {
        if ($response->status === self::HTTP_NOT_FOUND) {
            return null;
        }
        if ($response->status === self::HTTP_NO_CONTENT) {
            return new Node([self::LIST_KEY => []], $path);
        }
        if ($response->status === self::HTTP_UNAUTHORIZED || $response->status === self::HTTP_FORBIDDEN) {
            throw new SourceError("{$path}: {$this->label} rejected the credentials or they lack a required scope (HTTP {$response->status}).");
        }
        if ($response->status !== self::HTTP_OK) {
            throw new SourceError("{$path}: {$this->label} returned HTTP {$response->status}.");
        }
        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SourceError("{$path}: {$this->label} returned a response that isn't JSON.");
        }
        if (!is_array($decoded)) {
            throw new SourceError("{$path}: {$this->label} returned no object.");
        }

        return new Node(array_is_list($decoded) && $decoded !== [] ? [self::LIST_KEY => $decoded] : $decoded, $path);
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
