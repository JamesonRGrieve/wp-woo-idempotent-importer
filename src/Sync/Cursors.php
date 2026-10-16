<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Sync;

use DateTimeImmutable;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Options;

/**
 * Per source and kind, the start time of the last run that completed it with nothing failed or deferred.
 * The next incremental run asks for objects updated since then, minus an overlap for clock skew and
 * objects updated while that run was reading.
 */
final class Cursors
{
    public const OPTION = 'iim_cursors';
    public const OVERLAP = 'PT10M';

    public function __construct(private readonly string $sourceId) {}

    public function since(Kind $kind): ?DateTimeImmutable
    {
        $stored = $this->all()[$kind->value] ?? null;

        return $stored === null ? null : (new DateTimeImmutable($stored))->sub(new \DateInterval(self::OVERLAP));
    }

    public function advance(Kind $kind, string $runStartedAt): void
    {
        $all = $this->all();
        $all[$kind->value] = $runStartedAt;
        $this->store($all);
    }

    /** Forgets this source's cursors, so its next run reads everything. */
    public function reset(): void
    {
        $this->store([]);
    }

    /** @return array<string, string> kind value → run start */
    private function all(): array
    {
        $source = Options::array(self::OPTION)[$this->sourceId] ?? [];
        $all = [];
        foreach (is_array($source) ? $source : [] as $kind => $startedAt) {
            if (is_string($kind) && is_string($startedAt)) {
                $all[$kind] = $startedAt;
            }
        }

        return $all;
    }

    /** @param array<string, string> $cursors */
    private function store(array $cursors): void
    {
        $every = Options::array(self::OPTION);
        $every[$this->sourceId] = $cursors;
        update_option(self::OPTION, $every, false);
    }
}
