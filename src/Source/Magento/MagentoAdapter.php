<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Magento;

use IdempotentImporter\Source\DeferredAdapter;

/**
 * Magento / Adobe Commerce: deferred (listed, not built). When built:
 * Magento REST API (integration access token): products, categories, attributes, customers, orders,
 * invoices, credit memos, sales rules/coupons, CMS pages and blocks, URL rewrites.
 */
final class MagentoAdapter extends DeferredAdapter
{
    public const ID = 'magento';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Magento / Adobe Commerce';
    }
}
