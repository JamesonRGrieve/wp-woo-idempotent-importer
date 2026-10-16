<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\SquareOnline;

use IdempotentImporter\Source\DeferredAdapter;

/**
 * Square Online: deferred (listed, not built). When built:
 * Square APIs (OAuth or personal access token): Catalog, Inventory, Customers, Orders, Payments, Refunds,
 * Loyalty and Gift Cards; Square Online site pages have no public API.
 */
final class SquareOnlineAdapter extends DeferredAdapter
{
    public const ID = 'square_online';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Square Online';
    }
}
