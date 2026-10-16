<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

/**
 * Identities are `gid://<adapter id>/<Type>/<id>` (Shopify's own GIDs already have this form), so one
 * link table holds every source without collisions, and a source's links are found by prefix.
 */
final class Identity
{
    private const SCHEME = 'gid://';

    public static function of(string $adapterId, string $type, int|string $id): string
    {
        return self::SCHEME . "{$adapterId}/{$type}/{$id}";
    }

    /** `gid://<adapter id>/`: the prefix every identity minted by $adapterId starts with. */
    public static function prefix(string $adapterId): string
    {
        return self::SCHEME . "{$adapterId}/";
    }

    /**
     * The object's own id at its source (the last part of `gid://<adapter id>/<Type>/<id>`): a node's `id` is
     * its identity, so a mapper that needs the source's id reads it back from there.
     */
    public static function sourceId(string $gid): string
    {
        self::prefixOf($gid);
        $id = substr((string) strrchr($gid, '/'), 1);
        if ($id === '') {
            throw new SourceError("Not a source identity: {$gid}");
        }

        return $id;
    }

    /** The prefix of the source $gid came from. */
    public static function prefixOf(string $gid): string
    {
        $rest = str_starts_with($gid, self::SCHEME) ? substr($gid, strlen(self::SCHEME)) : '';
        $host = strstr($rest, '/', true);
        if ($host === false || $host === '') {
            throw new SourceError("Not a source identity: {$gid}");
        }

        return self::prefix($host);
    }
}
