<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

use DateTimeImmutable;
use IdempotentImporter\Model\Kind;

/**
 * Reads complete objects from one source platform. Every node it returns carries its identity as an
 * `id` of the form `gid://<adapter id>/<Type>/<id>`, so identities never collide across sources.
 */
interface SourceReader
{
    /** The store itself (name, contact, currency, policies). */
    public function shop(): Node;

    /**
     * One page of a kind. $cursor is the previous page's endCursor (null for the first page). $since asks
     * only for objects changed since then, and is only passed for kinds where isIncremental() is true.
     *
     * @throws SourceError
     */
    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page;

    /** One object by its identity, or null when the source no longer has it. */
    public function node(Kind $kind, string $gid): ?Node;

    /** Whether runs can ask this kind for only what changed since the last run. */
    public function isIncremental(Kind $kind): bool;
}
