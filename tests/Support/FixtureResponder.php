<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use IdempotentImporter\Http\HttpResponse;

/**
 * Answers a REST source's requests from one store state's fixtures (see SourceFixtures): by method and
 * path, the query parameters each fixture names (others, like page sizes, are ignored) and, for POST
 * queries, the body values it names. Unknown requests get a 404, as the source would answer. Shared by
 * the unit tests' transport and the end-to-end fake store.
 */
final class FixtureResponder implements Responder
{
    private const HTTP_OK = 200;
    private const HTTP_NOT_FOUND = 404;

    /** @var list<array{file: string, method: string, path: string, query: array<string, string>, body: array<string, mixed>, response: array<array-key, mixed>}> */
    private readonly array $fixtures;

    public function __construct(string $source, string $state)
    {
        $this->fixtures = SourceFixtures::state($source, $state);
    }

    public function respond(string $method, string $url, string $body): HttpResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = self::query((string) parse_url($url, PHP_URL_QUERY));
        $sent = $body === '' ? [] : json_decode($body, true);
        $sent = is_array($sent) ? $sent : [];
        foreach ($this->fixtures as $fixture) {
            if ($fixture['method'] === $method && $fixture['path'] === $path && self::queryMatches($fixture['query'], $query) && self::bodyMatches($fixture['body'], $sent)) {
                return new HttpResponse(self::HTTP_OK, json_encode($fixture['response'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            }
        }

        return new HttpResponse(self::HTTP_NOT_FOUND, '{"error":"Not found"}');
    }

    /**
     * @param array<string, string> $recorded
     * @param array<string, list<string>> $sent
     */
    private static function queryMatches(array $recorded, array $sent): bool
    {
        foreach ($recorded as $name => $value) {
            if (($sent[$name] ?? null) !== [$value]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $recorded
     * @param array<array-key, mixed> $sent
     */
    private static function bodyMatches(array $recorded, array $sent): bool
    {
        foreach ($recorded as $name => $value) {
            if (!array_key_exists($name, $sent) || $sent[$name] !== $value) {
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
}
