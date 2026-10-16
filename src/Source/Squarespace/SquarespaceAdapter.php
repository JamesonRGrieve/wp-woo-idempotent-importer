<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Squarespace;

use IdempotentImporter\Source\DeferredAdapter;

/**
 * Squarespace: deferred (listed, not built). When built:
 * Commerce APIs (REST, API key or OAuth): Products, Inventory, Orders, Profiles (customers), Transactions.
 * Pages, blog posts and site styling have no public API; they need the site export (WordPress XML).
 */
final class SquarespaceAdapter extends DeferredAdapter
{
    public const ID = 'squarespace';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Squarespace';
    }
}
