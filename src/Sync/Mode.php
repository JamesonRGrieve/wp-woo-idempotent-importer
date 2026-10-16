<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Sync;

/**
 * Incremental runs read only what changed since the last complete run of each kind. Full runs read
 * everything and retire copies of objects Shopify no longer has.
 */
enum Mode: string
{
    case Incremental = 'incremental';
    case Full = 'full';
}
