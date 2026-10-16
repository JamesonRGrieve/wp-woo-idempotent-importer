<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy;

use IdempotentImporter\Source\DeferredAdapter;

/**
 * Etsy: deferred (listed, not built). When built:
 * Etsy Open API v3 (x-api-key keystring:shared_secret + OAuth 2 PKCE): shops, listings with inventory,
 * images and translations, shop sections, receipts with transactions, reviews, shipping profiles.
 */
final class EtsyAdapter extends DeferredAdapter
{
    public const ID = 'etsy';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Etsy';
    }
}
