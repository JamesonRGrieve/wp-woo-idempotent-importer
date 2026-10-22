<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapters;

/** Operator settings for tests: every kind, the defaults otherwise. */
final class TestSettings
{
    public static function all(string $storeDomain = ''): Settings
    {
        return new Settings(
            source: Adapters::DEFAULT,
            storeDomain: $storeDomain,
            kinds: Kind::SYNC_ORDER,
            filters: [],
            intervalMinutes: 60,
            fullReconcileHours: 24,
            storeSettings: false,
            shippingZones: false,
            redirects: true,
            localiseImages: true,
            cleanHtml: false,
            preserveOrderNumbers: true,
            generateMissingSkus: false,
            defaultAuthor: 0,
            notifyEmail: '',
        );
    }
}
