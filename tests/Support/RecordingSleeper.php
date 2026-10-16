<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use IdempotentImporter\Http\Sleeper;

/** Records waits instead of sleeping; its clock starts at START and moves only by the waits (or advance()). */
final class RecordingSleeper implements Sleeper
{
    public const START = 1_800_000_000.0;

    /** @var list<float> */
    public array $waits = [];

    private float $now = self::START;

    public function sleep(float $seconds): void
    {
        $this->waits[] = $seconds;
        $this->now += $seconds;
    }

    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
