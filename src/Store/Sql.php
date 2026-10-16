<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Store;

use RuntimeException;
use wpdb;

/**
 * Prepared SQL. Every query is a literal string; table names go through the %i identifier placeholder
 * and values through %s/%d, so nothing is ever interpolated. A query WordPress refuses to prepare fails
 * loudly instead of running as null.
 */
final class Sql
{
    /** @param literal-string $query */
    public static function prepare(wpdb $db, string $query, string|int ...$args): string
    {
        $prepared = $db->prepare($query, ...$args);
        if (!is_string($prepared) || $prepared === '') {
            throw new RuntimeException('WordPress could not prepare a query.');
        }

        return $prepared;
    }

    /** @param literal-string $query */
    public static function execute(wpdb $db, string $query, string|int ...$args): void
    {
        if ($db->query(self::prepare($db, $query, ...$args)) === false) {
            throw new RuntimeException('A database write failed: ' . $db->last_error);
        }
    }

    /**
     * @param literal-string $query
     *
     * @return list<array<string, string|null>>
     */
    public static function rows(wpdb $db, string $query, string|int ...$args): array
    {
        $rows = $db->get_results(self::prepare($db, $query, ...$args), ARRAY_A);
        $typed = [];
        foreach ($rows ?? [] as $row) {
            $columns = [];
            foreach ($row as $column => $value) {
                $columns[(string) $column] = is_scalar($value) ? (string) $value : null;
            }
            $typed[] = $columns;
        }

        return $typed;
    }

    /**
     * @param literal-string $query
     *
     * @return array<string, string|null>|null
     */
    public static function row(wpdb $db, string $query, string|int ...$args): ?array
    {
        return self::rows($db, $query, ...$args)[0] ?? null;
    }
}
