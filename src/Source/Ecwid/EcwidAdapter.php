<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Ecwid;

use IdempotentImporter\Source\DeferredAdapter;

/**
 * Ecwid (Lightspeed): deferred (listed, not built). When built:
 * Ecwid REST API v3 (store id + secret token): Products, Categories, Customers, Orders, Discount coupons,
 * Promotions, Store profile and Instant site pages; webhooks are available per app.
 */
final class EcwidAdapter extends DeferredAdapter
{
    public const ID = 'ecwid';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Ecwid (Lightspeed)';
    }
}
