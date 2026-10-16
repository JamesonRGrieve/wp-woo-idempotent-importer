<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Store\RunRecord;
use PHPUnit\Framework\TestCase;

final class RunRecordTest extends TestCase
{
    public function testARunsStartIsShownToTheSecondThoughStoredToTheMicrosecond(): void
    {
        $run = RunRecord::fromRow([
            'id' => 'run-1',
            'mode' => 'full',
            'dry_run' => '1',
            'status' => 'complete',
            'started_at' => '2026-10-16 20:23:15.417203',
            'finished_at' => '2026-10-16 20:23:16',
            'counts' => '{"product":{"created":2}}',
            'message' => 'product: created 2',
        ]);

        self::assertSame('2026-10-16 20:23:15', $run->startedAt);
        self::assertSame('full (dry run)', $run->label());
        self::assertSame(2, $run->counts->writes());
    }
}
