<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Sync;

use IdempotentImporter\Model\Kind;

/** Per-kind outcome counts of a run; persisted as JSON between steps and in the run log. */
final class RunCounts
{
    /** @param array<string, array<string, int>> $counts kind value => outcome value => count */
    public function __construct(private array $counts = []) {}

    /** @param array<array-key, mixed> $stored */
    public static function fromArray(array $stored): self
    {
        $counts = [];
        foreach ($stored as $kind => $outcomes) {
            if (!is_string($kind) || !is_array($outcomes)) {
                continue;
            }
            foreach ($outcomes as $outcome => $count) {
                if (is_string($outcome) && is_int($count)) {
                    $counts[$kind][$outcome] = $count;
                }
            }
        }

        return new self($counts);
    }

    public function add(Kind $kind, Outcome $outcome): void
    {
        $this->counts[$kind->value][$outcome->value] = $this->get($kind, $outcome) + 1;
    }

    public function get(Kind $kind, Outcome $outcome): int
    {
        return $this->counts[$kind->value][$outcome->value] ?? 0;
    }

    public function isIncomplete(Kind $kind): bool
    {
        foreach (Outcome::cases() as $outcome) {
            if ($outcome->isIncomplete() && $this->get($kind, $outcome) > 0) {
                return true;
            }
        }

        return false;
    }

    /** Objects written to WordPress (created, updated or retired). Zero means the run changed nothing. */
    public function writes(): int
    {
        $total = 0;
        foreach (array_keys($this->counts) as $kind) {
            foreach ([Outcome::Created, Outcome::Updated, Outcome::Retired] as $outcome) {
                $total += $this->counts[$kind][$outcome->value] ?? 0;
            }
        }

        return $total;
    }

    /** @return array<string, array<string, int>> */
    public function toArray(): array
    {
        return $this->counts;
    }

    /** One line per kind, e.g. "product: created 3, skipped 40". */
    public function summary(): string
    {
        $lines = [];
        foreach ($this->counts as $kind => $outcomes) {
            $parts = [];
            foreach ($outcomes as $outcome => $count) {
                $parts[] = "{$outcome} {$count}";
            }
            $lines[] = "{$kind}: " . implode(', ', $parts);
        }

        return $lines === [] ? 'nothing to do' : implode("\n", $lines);
    }
}
