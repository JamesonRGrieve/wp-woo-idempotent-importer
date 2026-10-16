<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Store;

use IdempotentImporter\Model\Kind;
use wpdb;

/**
 * Old Shopify paths → where they now live: either a mirrored object (kind + GID, resolved to its
 * permalink at request time so slug changes follow) or a literal target from a Shopify URL redirect.
 */
final class RedirectStore
{
    public function __construct(private readonly wpdb $db, private readonly Tables $tables) {}

    /**
     * Makes the object's redirect paths exactly $paths: adds new ones, removes ones it no longer has.
     *
     * @param list<string> $paths normalised paths
     */
    public function syncObject(Kind $kind, string $gid, array $paths, ?string $targetUrl = null): void
    {
        foreach (array_diff($this->paths($kind, $gid), $paths) as $stale) {
            Sql::execute($this->db, 'DELETE FROM %i WHERE path = %s', $this->table(), $stale);
        }
        foreach ($paths as $path) {
            Sql::execute(
                $this->db,
                'INSERT INTO %i (path, kind, gid, target_url) VALUES (%s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE kind = VALUES(kind), gid = VALUES(gid), target_url = VALUES(target_url)',
                $this->table(),
                $path,
                $kind->value,
                $gid,
                $targetUrl ?? '',
            );
        }
    }

    /** @return array{kind: Kind, gid: string, target_url: ?string}|null */
    public function find(string $path): ?array
    {
        $row = Sql::row($this->db, 'SELECT kind, gid, target_url FROM %i WHERE path = %s', $this->table(), $path);
        $kind = $row === null ? null : Kind::tryFrom((string) $row['kind']);
        if ($row === null || $kind === null) {
            return null;
        }
        $target = $row['target_url'];

        return ['kind' => $kind, 'gid' => (string) $row['gid'], 'target_url' => $target === null || $target === '' ? null : $target];
    }

    /** @return list<string> */
    private function paths(Kind $kind, string $gid): array
    {
        return array_map(
            static fn(array $row): string => (string) $row['path'],
            Sql::rows($this->db, 'SELECT path FROM %i WHERE kind = %s AND gid = %s', $this->table(), $kind->value, $gid),
        );
    }

    private function table(): string
    {
        return $this->tables->name(Tables::REDIRECTS);
    }
}
