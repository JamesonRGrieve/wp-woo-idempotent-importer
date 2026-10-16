<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Http;

use IdempotentImporter\Source\RetryLater;

/**
 * Keeps one client's requests under its source's RateLimit: no closer together than the documented rate,
 * and, when a response says the window is down to the reserve, nothing more until the window resets. A wait
 * too long to sit out inside a request is a RetryLater, so the run pauses instead.
 */
final class RequestPacer
{
    private ?float $lastRequestAt = null;
    private ?float $quietUntil = null;

    public function __construct(
        private readonly RateLimit $limit,
        private readonly Sleeper $sleeper,
    ) {}

    /** Waits until the next request is within the limits. */
    public function beforeRequest(string $label): void
    {
        $now = $this->sleeper->now();
        $next = max(
            $this->lastRequestAt === null ? $now : $this->lastRequestAt + $this->limit->minInterval(),
            $this->quietUntil ?? $now,
        );
        $wait = $next - $now;
        if ($wait > RetryPolicy::MAX_INLINE_WAIT_SECONDS) {
            throw new RetryLater($wait, "{$label}'s request allowance is used up until its window resets.");
        }
        if ($wait > 0) {
            $this->sleeper->sleep($wait);
        }
        $this->lastRequestAt = $this->sleeper->now();
    }

    /** Notes how many requests the window has left, as the response reports it. */
    public function afterResponse(HttpResponse $response): void
    {
        if ($this->limit->remainingHeader === null) {
            return;
        }
        $remaining = $response->header($this->limit->remainingHeader);
        if ($remaining === null || !is_numeric($remaining) || (int) $remaining > $this->limit->reserve) {
            return;
        }
        $now = $this->sleeper->now();
        $seconds = $this->secondsUntilReset($response, $now);
        if ($seconds !== null) {
            $this->quietUntil = $now + $seconds;
        }
    }

    private function secondsUntilReset(HttpResponse $response, float $now): ?float
    {
        $reset = $this->limit->resetHeader === null ? null : $response->header($this->limit->resetHeader);

        return $reset === null ? $this->limit->windowSeconds : $this->limit->resetFormat->secondsUntil($reset, $now);
    }
}
