<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Store;

use IdempotentImporter\Sync\RunCounts;

/** A finished or running run, as the run log stores it. */
final readonly class RunRecord
{
    public function __construct(
        public string $id,
        public string $mode,
        public bool $dryRun,
        public string $status,
        public string $startedAt,
        public RunCounts $counts,
        public string $message,
    ) {}

    /** @param array<string, string|null> $row */
    public static function fromRow(array $row): self
    {
        $counts = json_decode((string) $row['counts'], true);

        return new self(
            (string) $row['id'],
            (string) $row['mode'],
            $row['dry_run'] === '1',
            (string) $row['status'],
            (string) $row['started_at'],
            RunCounts::fromArray(is_array($counts) ? $counts : []),
            (string) $row['message'],
        );
    }

    public function label(): string
    {
        return $this->mode . ($this->dryRun ? ' (dry run)' : '');
    }
}
