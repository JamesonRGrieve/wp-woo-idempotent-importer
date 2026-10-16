<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

use RuntimeException;

/**
 * Shopify's real requested costs, recorded from a live store by tools/calibrate-query-cost.php in
 * tests/fixtures/shopify-query-cost.json: each read operation's cost at its default page sizes, and the
 * connection multiplier m(n) measured as the cost of `urlRedirects(first: n) { nodes { id } }` less its fixed
 * part (the connection's 2; its `nodes` list's 1 is what m multiplies).
 */
final class CostCalibration
{
    /** The probe's cost besides the multiplier: 2 for the connection (m × 1 for its `nodes`). */
    public const PROBE_FIXED_COST = 2;

    private const PLACEHOLDER_URI = 'https://example.com/webhooks';

    public static function path(): string
    {
        return dirname(__DIR__) . '/tests/fixtures/shopify-query-cost.json';
    }

    /** @param array<string, mixed> $calibration */
    public static function write(array $calibration): void
    {
        $json = json_encode($calibration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents(self::path(), $json) === false) {
            throw new RuntimeException('Could not write ' . self::path());
        }
    }

    /** @return array<int, int> page size => multiplier */
    public static function multipliers(): array
    {
        $recorded = self::load()['connectionMultipliers'] ?? null;
        if (!is_array($recorded)) {
            throw new RuntimeException('The calibration has no connectionMultipliers');
        }
        $multipliers = [];
        foreach ($recorded as $size => $multiplier) {
            if (!is_numeric($size) || !is_int($multiplier)) {
                throw new RuntimeException("Multiplier for {$size} is not a page size and an integer");
            }
            $multipliers[(int) $size] = $multiplier;
        }

        return $multipliers;
    }

    /** @return array<string, array{variables: array<string, mixed>, cost: int}> operation => recorded call */
    public static function operations(): array
    {
        $operations = [];
        foreach (Json::object(self::load()['operations'] ?? null, 'operations') as $operation => $recorded) {
            $recorded = Json::object($recorded, $operation);
            $cost = $recorded['requestedQueryCost'] ?? null;
            $operations[$operation] = [
                'variables' => Json::object($recorded['variables'] ?? [], "{$operation} variables"),
                'cost' => is_int($cost) ? $cost : throw new RuntimeException("{$operation} has no recorded cost"),
            ];
        }

        return $operations;
    }

    /**
     * Placeholder values for the required non-size variables a document declares ($id, $uri).
     *
     * @return array<string, string>
     */
    public static function placeholders(string $document, string $id): array
    {
        $values = [];
        if (str_contains($document, '$id: ID!')) {
            $values['id'] = $id;
        }
        if (str_contains($document, '$uri: String!')) {
            $values['uri'] = self::PLACEHOLDER_URI;
        }

        return $values;
    }

    public static function stripComments(string $document): string
    {
        return ltrim((string) preg_replace('/^\s*#.*$/m', '', $document));
    }

    /** @return array<string, mixed> */
    private static function load(): array
    {
        $raw = file_get_contents(self::path());
        if ($raw === false) {
            throw new RuntimeException('Cannot read ' . self::path());
        }

        return Json::object(json_decode($raw, true, 512, JSON_THROW_ON_ERROR), 'The calibration');
    }
}
