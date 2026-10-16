<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Http;

/** Waiting between throttled or retried requests; injected so tests run without real delays. */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
