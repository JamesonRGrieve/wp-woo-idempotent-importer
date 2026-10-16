<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Store;

use IdempotentShopifyImporter\Model\Kind;
use wpdb;

/** The GIDs a full pass has seen, so links it didn't see can be retired. Scratch data, cleared per run. */
final class SeenStore
{
    public function __construct(private readonly wpdb $db, private readonly Tables $tables) {}

    public function mark(string $runId, Kind $kind, string $gid): void
    {
        Sql::execute($this->db, 'INSERT IGNORE INTO %i (run_id, kind, gid) VALUES (%s, %s, %s)', $this->table(), $runId, $kind->value, $gid);
    }

    public function clear(string $runId): void
    {
        Sql::execute($this->db, 'DELETE FROM %i WHERE run_id = %s', $this->table(), $runId);
    }

    private function table(): string
    {
        return $this->tables->name(Tables::SEEN);
    }
}
