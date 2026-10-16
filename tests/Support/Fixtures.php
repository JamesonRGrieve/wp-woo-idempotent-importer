<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use GraphQL\Type\Definition\ResolveInfo;
use RuntimeException;

/**
 * The recorded Shopify exchanges in tests/fixtures/shopify/<store state>/<name>.json. Each store state
 * (v1, v2, ...) is a complete snapshot of a store; the end-to-end suite serves one state at a time.
 */
final class Fixtures
{
    public static function root(): string
    {
        return dirname(__DIR__) . '/fixtures/shopify';
    }

    /** @return array<string, Fixture> keyed "<state>/<name>" */
    public static function all(): array
    {
        $fixtures = [];
        foreach (glob(self::root() . '/*/*.json') ?: [] as $path) {
            $key = basename(dirname($path)) . '/' . basename($path, '.json');
            $fixtures[$key] = self::load($path);
        }
        ksort($fixtures);

        return $fixtures;
    }

    /** @return list<Fixture> */
    public static function state(string $state): array
    {
        $fixtures = [];
        foreach (glob(self::root() . "/{$state}/*.json") ?: [] as $path) {
            $fixtures[] = self::load($path);
        }

        return $fixtures;
    }

    public static function load(string $path): Fixture
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$path}");
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['operation'] ?? null) || !is_array($decoded['data'] ?? null)) {
            throw new RuntimeException("{$path} needs an `operation` string and a `data` object");
        }
        $variables = $decoded['variables'] ?? [];
        if (!is_array($variables)) {
            throw new RuntimeException("{$path}: `variables` must be an object");
        }

        /** @var array<string, mixed> $variables */
        /** @var array<string, mixed> $data */
        $data = $decoded['data'];

        return new Fixture($decoded['operation'], $variables, $data);
    }

    /** Resolves every field from the recorded response by its name (the operations use no aliases). */
    public static function fieldResolver(mixed $source, mixed $args, mixed $context, ResolveInfo $info): mixed
    {
        return is_array($source) ? ($source[$info->fieldName] ?? null) : null;
    }

    /** The first path where two decoded JSON values differ, or null when they are equal. */
    public static function firstDifference(mixed $expected, mixed $actual, string $path = '$'): ?string
    {
        if (is_array($expected) && is_array($actual)) {
            foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
                if (!array_key_exists($key, $actual)) {
                    return "{$path}.{$key} is in the fixture but not selected by the operation";
                }
                if (!array_key_exists($key, $expected)) {
                    return "{$path}.{$key} is selected by the operation but missing from the fixture";
                }
                $difference = self::firstDifference($expected[$key], $actual[$key], "{$path}.{$key}");
                if ($difference !== null) {
                    return $difference;
                }
            }

            return null;
        }
        if ($expected === $actual || (is_numeric($expected) && is_numeric($actual) && (float) $expected === (float) $actual)) {
            return null;
        }

        return "{$path}: fixture has " . json_encode($expected) . ', schema serialises ' . json_encode($actual);
    }
}
