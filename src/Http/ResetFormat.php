<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Http;

/** How a source's rate-limit reset header says when the window resets. */
enum ResetFormat
{
    case SecondsFromNow;
    case MillisecondsFromNow;
    case EpochSeconds;

    private const MILLISECONDS_PER_SECOND = 1000;

    /** Seconds from $now until the reset $value names, or null when $value isn't a number. */
    public function secondsUntil(string $value, float $now): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        $seconds = match ($this) {
            self::SecondsFromNow => $number,
            self::MillisecondsFromNow => $number / self::MILLISECONDS_PER_SECOND,
            self::EpochSeconds => $number - $now,
        };

        return max(0.0, $seconds);
    }
}
