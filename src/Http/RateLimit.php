<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Http;

/**
 * A source's documented request limits, so a long first import paces itself under them instead of running
 * into them (and risking the source locking the key out): a sustained request rate, the cool-down after a
 * 429 that carries no timing hint, and the response headers, if any, that say how many requests remain in
 * the current window and when it resets (or, for a source that reports only what remains, the window's
 * fixed length). The default is no pacing.
 */
final readonly class RateLimit
{
    /** Requests kept in hand: once the window has this few left, the next request waits for the reset. */
    public const DEFAULT_RESERVE = 1;

    public function __construct(
        public ?float $requestsPerSecond = null,
        public ?float $cooldownSeconds = null,
        public ?string $remainingHeader = null,
        public ?string $resetHeader = null,
        public ResetFormat $resetFormat = ResetFormat::SecondsFromNow,
        public int $reserve = self::DEFAULT_RESERVE,
        public ?float $windowSeconds = null,
    ) {}

    /** A rate of $requests per $seconds (as sources document it, e.g. 300 per 60). */
    public static function perPeriod(int $requests, float $seconds): float
    {
        return $requests / $seconds;
    }

    /** The least time between two requests, in seconds (0 when the source sets no rate). */
    public function minInterval(): float
    {
        return $this->requestsPerSecond !== null && $this->requestsPerSecond > 0 ? 1.0 / $this->requestsPerSecond : 0.0;
    }
}
