<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use IdempotentImporter\Http\HttpResponse;

/**
 * Answers Etsy API requests from one store state's fixtures, as Etsy would: a GET by its path and the
 * query parameters each fixture names (others, like `limit`, are ignored), and the OAuth token endpoint
 * with a token for the shop owner. Unknown requests get a 404. Shared by the unit tests' transport and
 * the end-to-end fake Etsy server.
 */
final class EtsyResponder implements Responder
{
    public const OWNER_USER_ID = 7001;
    public const ACCESS_TOKEN = self::OWNER_USER_ID . '.fixture-access-token';
    public const REFRESH_TOKEN = 'fixture-refresh-token';
    public const TOKEN_PATH = '/v3/public/oauth/token';

    private const HTTP_OK = 200;
    private const HTTP_NOT_FOUND = 404;

    /** @var list<array{file: string, path: string, query: array<string, string>, response: array<array-key, mixed>}> */
    private readonly array $fixtures;

    public function __construct(string $state)
    {
        $this->fixtures = EtsyFixtures::state($state);
    }

    public function respond(string $method, string $url, string $body): HttpResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($method === 'POST' && $path === self::TOKEN_PATH) {
            return self::json(['access_token' => self::ACCESS_TOKEN, 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => self::REFRESH_TOKEN]);
        }
        $query = self::query((string) parse_url($url, PHP_URL_QUERY));
        foreach ($this->fixtures as $fixture) {
            if ($method === 'GET' && $fixture['path'] === $path && self::matches($fixture['query'], $query)) {
                return self::json($fixture['response']);
            }
        }

        return new HttpResponse(self::HTTP_NOT_FOUND, '{"error":"Not found"}');
    }

    /**
     * @param array<string, string> $recorded
     * @param array<string, list<string>> $sent
     */
    private static function matches(array $recorded, array $sent): bool
    {
        foreach ($recorded as $name => $value) {
            if (($sent[$name] ?? null) !== [$value]) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, list<string>> each parameter's values (a repeated key has several) */
    private static function query(string $query): array
    {
        $values = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $values[rawurldecode($name)][] = rawurldecode($value);
        }

        return $values;
    }

    /** @param array<array-key, mixed> $body */
    private static function json(array $body): HttpResponse
    {
        return new HttpResponse(self::HTTP_OK, json_encode($body, JSON_THROW_ON_ERROR));
    }
}
