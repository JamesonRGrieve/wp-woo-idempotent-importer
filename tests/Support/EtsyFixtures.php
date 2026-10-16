<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use RuntimeException;

/**
 * Recorded Etsy API responses for one store state (tests/fixtures/etsy/<state>/*.json). Each file is
 * `{"request": {"path": …, "query": {…}}, "response": {…}}`: the response Etsy gives a GET of that path
 * whose query includes those parameters. Every response is validated against Etsy's OpenAPI spec.
 */
final class EtsyFixtures
{
    /** @return list<array{file: string, path: string, query: array<string, string>, response: array<array-key, mixed>}> */
    public static function state(string $state): array
    {
        $files = glob(self::root() . "/{$state}/*.json") ?: [];
        sort($files);
        if ($files === []) {
            throw new RuntimeException("No Etsy fixtures for state {$state}.");
        }

        return array_map(self::load(...), $files);
    }

    /** @return list<string> every state directory */
    public static function states(): array
    {
        return array_map(basename(...), glob(self::root() . '/*', GLOB_ONLYDIR) ?: []);
    }

    private static function root(): string
    {
        return dirname(__DIR__) . '/fixtures/etsy';
    }

    /** @return array{file: string, path: string, query: array<string, string>, response: array<array-key, mixed>} */
    private static function load(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $request = is_array($decoded) ? ($decoded['request'] ?? null) : null;
        $response = is_array($decoded) ? ($decoded['response'] ?? null) : null;
        if (!is_array($request) || !is_string($request['path'] ?? null) || !is_array($response)) {
            throw new RuntimeException("{$file} is not a request/response fixture.");
        }
        $query = [];
        foreach (is_array($request['query'] ?? null) ? $request['query'] : [] as $name => $value) {
            $query[(string) $name] = is_scalar($value) ? (string) $value : throw new RuntimeException("{$file}: query {$name} is not scalar.");
        }

        return ['file' => $file, 'path' => $request['path'], 'query' => $query, 'response' => $response];
    }
}
