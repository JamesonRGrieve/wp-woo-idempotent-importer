<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Sync;

use IdempotentImporter\Model\Kind;

/** A run in progress, persisted between steps so a run survives across scheduler actions. */
final class RunState
{
    public const OPTION = 'iim_run_state';

    /** @param list<Kind> $kinds */
    public function __construct(
        public readonly string $id,
        public readonly Mode $mode,
        public readonly bool $dryRun,
        public readonly array $kinds,
        public readonly string $startedAt,
        public int $index = 0,
        public ?string $cursor = null,
        public RunCounts $counts = new RunCounts(),
    ) {}

    public function current(): ?Kind
    {
        return $this->kinds[$this->index] ?? null;
    }

    public function advance(): void
    {
        ++$this->index;
        $this->cursor = null;
    }

    public static function load(): ?self
    {
        $stored = get_option(self::OPTION);
        if (!is_array($stored) || !is_string($stored['id'] ?? null)) {
            return null;
        }
        $mode = Mode::tryFrom(is_string($stored['mode'] ?? null) ? $stored['mode'] : '');
        $kinds = [];
        foreach (is_array($stored['kinds'] ?? null) ? $stored['kinds'] : [] as $kind) {
            $parsed = is_string($kind) ? Kind::tryFrom($kind) : null;
            if ($parsed !== null) {
                $kinds[] = $parsed;
            }
        }
        if ($mode === null || !is_string($stored['started_at'] ?? null)) {
            return null;
        }

        return new self(
            $stored['id'],
            $mode,
            ($stored['dry_run'] ?? false) === true,
            $kinds,
            $stored['started_at'],
            is_int($stored['index'] ?? null) ? $stored['index'] : 0,
            is_string($stored['cursor'] ?? null) ? $stored['cursor'] : null,
            RunCounts::fromArray(is_array($stored['counts'] ?? null) ? $stored['counts'] : []),
        );
    }

    public function save(): void
    {
        update_option(self::OPTION, [
            'id' => $this->id,
            'mode' => $this->mode->value,
            'dry_run' => $this->dryRun,
            'kinds' => array_map(static fn(Kind $kind): string => $kind->value, $this->kinds),
            'started_at' => $this->startedAt,
            'index' => $this->index,
            'cursor' => $this->cursor,
            'counts' => $this->counts->toArray(),
        ], false);
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }
}
