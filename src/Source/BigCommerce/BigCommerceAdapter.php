<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce;

use IdempotentImporter\Source\DeferredAdapter;

/**
 * BigCommerce: deferred (listed, not built). When built:
 * REST Management API v3/v2 (store hash + API account token): Catalog (products, variants, categories,
 * brands, custom fields), Customers, Orders (v2), Coupons/Promotions, Pages, Blog, Redirects; webhooks.
 */
final class BigCommerceAdapter extends DeferredAdapter
{
    public const ID = 'bigcommerce';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'BigCommerce';
    }
}
