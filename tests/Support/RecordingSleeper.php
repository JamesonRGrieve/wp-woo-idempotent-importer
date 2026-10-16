<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Tests\Support;

use IdempotentShopifyImporter\Shopify\Sleeper;

final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public array $waits = [];

    public function sleep(float $seconds): void
    {
        $this->waits[] = $seconds;
    }
}
