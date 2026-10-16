<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix;

use IdempotentImporter\Source\DeferredAdapter;

/**
 * Wix: deferred (listed, not built). When built:
 * Wix REST APIs (API key + site id, or OAuth app): Stores Catalog V3, Orders (eCommerce), Contacts, Blog,
 * Data collections (CMS), Coupons and URL redirects; webhooks are available per app.
 */
final class WixAdapter extends DeferredAdapter
{
    public const ID = 'wix';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Wix';
    }
}
