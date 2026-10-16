<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use RuntimeException;

/**
 * Recorded REST API responses of one source for one store state (tests/fixtures/<source>/<state>/*.json).
 * Each file is `{"request": {"method"?, "path", "query"?, "body"?}, "response": {…}}`: the response the
 * source gives a request to that path (GET unless named) whose query includes those parameters and, for a
 * JSON POST query, whose body has those top-level values. Every response is validated against the
 * source's published API spec (tools/validate-fixtures.php).
 */
final class SourceFixtures
{
    /** @return list<array{file: string, method: string, path: string, query: array<string, string>, body: array<string, mixed>, response: array<array-key, mixed>}> */
    public static function state(string $source, string $state): array
    {
        $files = glob(self::root() . "/{$source}/{$state}/*.json") ?: [];
        sort($files);
        if ($files === []) {
            throw new RuntimeException("No {$source} fixtures for state {$state}.");
        }

        return array_map(self::load(...), $files);
    }

    /** @return list<string> every state directory of a source */
    public static function states(string $source): array
    {
        return array_map(basename(...), glob(self::root() . "/{$source}/*", GLOB_ONLYDIR) ?: []);
    }

    private static function root(): string
    {
        return dirname(__DIR__) . '/fixtures';
    }

    /** @return array{file: string, method: string, path: string, query: array<string, string>, body: array<string, mixed>, response: array<array-key, mixed>} */
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
            $query[(string) $name] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => throw new RuntimeException("{$file}: query {$name} is not scalar."),
            };
        }
        $body = [];
        foreach (is_array($request['body'] ?? null) ? $request['body'] : [] as $name => $value) {
            $body[(string) $name] = $value;
        }
        $method = is_string($request['method'] ?? null) ? strtoupper($request['method']) : 'GET';

        return ['file' => $file, 'method' => $method, 'path' => $request['path'], 'query' => $query, 'body' => $body, 'response' => $response];
    }
}
