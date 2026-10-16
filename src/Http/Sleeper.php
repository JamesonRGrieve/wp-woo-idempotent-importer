<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Http;

/** Time for throttled or retried requests: waiting, and the clock pacing is measured on. Injected so tests run without real delays. */
interface Sleeper
{
    public function sleep(float $seconds): void;

    /** Seconds since the Unix epoch, with fractions. */
    public function now(): float;
}
