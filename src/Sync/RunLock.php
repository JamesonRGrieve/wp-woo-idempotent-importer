<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Sync;

/**
 * One run at a time. add_option is atomic (the option name is unique), so only one caller can take a
 * free lock; a lock whose holder stopped refreshing it expires.
 */
final class RunLock
{
    public const OPTION = 'iim_run_lock';
    public const TTL_SECONDS = 600;

    public function acquire(string $runId): bool
    {
        $lock = ['run' => $runId, 'expires' => time() + self::TTL_SECONDS];
        if (add_option(self::OPTION, $lock, '', false)) {
            return true;
        }
        $current = get_option(self::OPTION);
        if (is_array($current) && is_int($current['expires'] ?? null) && $current['expires'] < time()) {
            update_option(self::OPTION, $lock, false);

            return true;
        }

        return false;
    }

    public function refresh(string $runId): void
    {
        $this->holdUntil($runId, time());
    }

    /** Keeps the lock through a pause: it expires TTL_SECONDS after $until, when the paused run resumes. */
    public function holdUntil(string $runId, int $until): void
    {
        if ($this->holder() === $runId) {
            update_option(self::OPTION, ['run' => $runId, 'expires' => $until + self::TTL_SECONDS], false);
        }
    }

    public function release(string $runId): void
    {
        if ($this->holder() === $runId) {
            delete_option(self::OPTION);
        }
    }

    /** @phpstan-impure */
    public function holder(): ?string
    {
        $current = get_option(self::OPTION);
        if (!is_array($current) || !is_string($current['run'] ?? null) || !is_int($current['expires'] ?? null)) {
            return null;
        }

        return $current['expires'] < time() ? null : $current['run'];
    }
}
