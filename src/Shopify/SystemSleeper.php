<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Shopify;

final class SystemSleeper implements Sleeper
{
    private const MICROSECONDS_PER_SECOND = 1_000_000;

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * self::MICROSECONDS_PER_SECOND));
        }
    }
}
