<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Store;

use IdempotentImporter\Model\Kind;
use wpdb;

/**
 * The identity map: (kind, source GID) → WordPress object id + content hash. Kept in its own table so
 * lookups are one indexed query for every kind (posts, terms, users, HPOS orders, attachments) alike.
 */
final class LinkStore
{
    private const NO_HASH = '';

    public function __construct(private readonly wpdb $db, private readonly Tables $tables) {}

    public function find(Kind $kind, string $gid): ?Link
    {
        $row = Sql::row($this->db, 'SELECT gid, target_id, hash FROM %i WHERE kind = %s AND gid = %s', $this->table(), $kind->value, $gid);

        return $row === null ? null : self::link($kind, $row);
    }

    public function targetId(Kind $kind, string $gid): ?int
    {
        return $this->find($kind, $gid)?->targetId;
    }

    /** Records a write. A link without a hash marks an identity only (e.g. a variation). */
    public function save(Kind $kind, string $gid, int $targetId, string $hash = self::NO_HASH): void
    {
        Sql::execute(
            $this->db,
            'INSERT INTO %i (kind, gid, target_id, hash, synced_at) VALUES (%s, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE target_id = VALUES(target_id), hash = VALUES(hash), synced_at = VALUES(synced_at)',
            $this->table(),
            $kind->value,
            $gid,
            $targetId,
            $hash,
            gmdate('Y-m-d H:i:s'),
        );
    }

    public function delete(Kind $kind, string $gid): void
    {
        Sql::execute($this->db, 'DELETE FROM %i WHERE kind = %s AND gid = %s', $this->table(), $kind->value, $gid);
    }

    /** @return list<Link> */
    public function all(Kind $kind): array
    {
        return self::links($kind, Sql::rows($this->db, 'SELECT gid, target_id, hash FROM %i WHERE kind = %s ORDER BY id', $this->table(), $kind->value));
    }

    /** @return list<Link> every link of a kind whose GID starts with $prefix */
    public function withPrefix(Kind $kind, string $prefix): array
    {
        return self::links($kind, Sql::rows(
            $this->db,
            'SELECT gid, target_id, hash FROM %i WHERE kind = %s AND gid LIKE %s ORDER BY id',
            $this->table(),
            $kind->value,
            $this->db->esc_like($prefix) . '%',
        ));
    }

    /**
     * Every link of a kind from one source (GID prefix) that a run did not see: the deletion candidates of
     * that source's full pass. Other sources' links are never candidates.
     *
     * @return list<Link>
     */
    public function unseen(Kind $kind, string $runId, string $prefix): array
    {
        return self::links($kind, Sql::rows(
            $this->db,
            'SELECT l.gid, l.target_id, l.hash FROM %i l
             LEFT JOIN %i s ON s.run_id = %s AND s.kind = l.kind AND s.gid = l.gid
             WHERE l.kind = %s AND l.gid LIKE %s AND s.gid IS NULL',
            $this->table(),
            $this->tables->name(Tables::SEEN),
            $runId,
            $kind->value,
            $this->db->esc_like($prefix) . '%',
        ));
    }

    private function table(): string
    {
        return $this->tables->name(Tables::LINKS);
    }

    /**
     * @param list<array<string, string|null>> $rows
     *
     * @return list<Link>
     */
    private static function links(Kind $kind, array $rows): array
    {
        return array_map(static fn(array $row): Link => self::link($kind, $row), $rows);
    }

    /** @param array<string, string|null> $row */
    private static function link(Kind $kind, array $row): Link
    {
        return new Link($kind, (string) $row['gid'], (int) $row['target_id'], (string) $row['hash']);
    }
}
