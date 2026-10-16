<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify\Mapping;

use IdempotentImporter\Source\Node;
use RuntimeException;

/**
 * Product reviews exported from a Shopify review app as CSV (Shopify has no reviews API). Judge.me and
 * Shopify Product Reviews exports are recognised by their column names; each row becomes a node with a
 * stable id derived from its content, so re-importing the same export changes nothing.
 */
final class ReviewCsv
{
    /** Shopify has no review id, so the identity is `gid://shopify/Review/<content hash>`. */
    public const ID_PREFIX = 'gid://shopify/Review/';

    /** Our field => the column names the known exports use for it. */
    private const COLUMNS = [
        'product_handle' => ['product_handle', 'handle'],
        'rating' => ['rating'],
        'title' => ['title'],
        'body' => ['body', 'review'],
        'author' => ['reviewer_name', 'author', 'name'],
        'email' => ['reviewer_email', 'email'],
        'created_at' => ['review_date', 'created_at', 'date'],
        'reply' => ['reply'],
        'state' => ['state', 'curated'],
        'verified' => ['verified', 'verified_buyer'],
    ];
    private const REQUIRED = ['product_handle', 'rating', 'body', 'created_at'];

    /** @return list<Node> */
    public static function parse(string $csv): array
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Could not open a memory stream for the CSV.');
        }
        try {
            fwrite($handle, $csv);
            rewind($handle);
            $header = fgetcsv($handle, null, ',', '"', '');
            if ($header === false) {
                return [];
            }
            $columns = self::columns(array_map(static fn(?string $name): string => strtolower(trim((string) $name)), $header));
            $nodes = [];
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $nodes[] = self::node($columns, $row);
            }

            return $nodes;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<string> $header
     *
     * @return array<string, int> field => column index
     */
    private static function columns(array $header): array
    {
        $columns = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($names as $name) {
                $index = array_search($name, $header, true);
                if ($index !== false) {
                    $columns[$field] = $index;
                    break;
                }
            }
        }
        $missing = array_diff(self::REQUIRED, array_keys($columns));
        if ($missing !== []) {
            throw new RuntimeException('The review CSV has no column for: ' . implode(', ', $missing));
        }

        return $columns;
    }

    /**
     * @param array<string, int> $columns
     * @param list<?string> $row
     */
    private static function node(array $columns, array $row): Node
    {
        $fields = [];
        foreach ($columns as $field => $index) {
            $value = trim((string) ($row[$index] ?? ''));
            $fields[$field] = $value === '' ? null : $value;
        }
        $fields['id'] = self::ID_PREFIX . hash('sha256', implode("\x1f", [
            $fields['product_handle'], $fields['email'] ?? '', $fields['created_at'], $fields['body'],
        ]));

        return new Node($fields, 'review');
    }
}
