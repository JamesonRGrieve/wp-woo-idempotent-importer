<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Sync;

/**
 * How long a run pauses when its source keeps pushing back (Source\RetryLater): at least what the source
 * asked for, doubling with each consecutive pause (1, 2, 4 … minutes) up to an hour. The pauses hold on for
 * about a day (MAX_CONSECUTIVE_PAUSES: two hours of growing pauses, then hourly ones), long enough to outlast a
 * spent daily quota without losing the run's place; after that the kind is given up as failed, so a source
 * that never recovers can't hold the run forever. A page read cleanly resets the count.
 */
final class PausePolicy
{
    public const BASE_SECONDS = 60;
    public const MAX_SECONDS = 3600;
    public const MAX_CONSECUTIVE_PAUSES = 30;

    /** Seconds to pause for the $pause-th consecutive pause (1-based), given the source's own hint. */
    public static function seconds(int $pause, float $hintSeconds): int
    {
        $backoff = self::BASE_SECONDS * (2 ** max(0, $pause - 1));

        return (int) min(self::MAX_SECONDS, max((int) ceil($hintSeconds), $backoff));
    }

    public static function exhausted(int $pause): bool
    {
        return $pause > self::MAX_CONSECUTIVE_PAUSES;
    }
}
