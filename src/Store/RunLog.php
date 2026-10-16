<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Store;

use DateTimeImmutable;
use DateTimeZone;
use wpdb;

/**
 * One row per run: mode, status, timing and outcome counts. A run's start is kept to the microsecond, so
 * runs started within the same second (a scheduled run, then one started by hand) list in the order they ran.
 */
final class RunLog
{
    public const RECENT_LIMIT = 20;
    private const STARTED_AT_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private readonly wpdb $db, private readonly Tables $tables) {}

    public function start(string $id, string $mode, bool $dryRun): void
    {
        Sql::execute(
            $this->db,
            'INSERT INTO %i (id, mode, dry_run, status, started_at, counts) VALUES (%s, %s, %d, %s, %s, %s)',
            $this->table(),
            $id,
            $mode,
            $dryRun ? 1 : 0,
            'running',
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(self::STARTED_AT_FORMAT),
            '{}',
        );
    }

    /** @param array<string, array<string, int>> $counts */
    public function finish(string $id, string $status, array $counts, string $message): void
    {
        Sql::execute(
            $this->db,
            'UPDATE %i SET status = %s, finished_at = %s, counts = %s, message = %s WHERE id = %s',
            $this->table(),
            $status,
            gmdate('Y-m-d H:i:s'),
            (string) wp_json_encode($counts),
            $message,
            $id,
        );
    }

    /** @return list<RunRecord> newest first */
    public function recent(int $limit = self::RECENT_LIMIT): array
    {
        return array_map(
            RunRecord::fromRow(...),
            Sql::rows($this->db, 'SELECT id, mode, dry_run, status, started_at, finished_at, counts, message FROM %i ORDER BY started_at DESC LIMIT %d', $this->table(), $limit),
        );
    }

    private function table(): string
    {
        return $this->tables->name(Tables::RUNS);
    }
}
