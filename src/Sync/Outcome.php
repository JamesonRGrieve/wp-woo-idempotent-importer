<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Sync;

/** What happened to one Shopify object in a run. */
enum Outcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Skipped = 'skipped';
    case Retired = 'retired';
    case Unsupported = 'unsupported';
    case Deferred = 'deferred';
    case Failed = 'failed';

    /** Outcomes that leave the kind incomplete for this run (its incremental cursor must not advance). */
    public function isIncomplete(): bool
    {
        return $this === self::Deferred || $this === self::Failed;
    }
}
