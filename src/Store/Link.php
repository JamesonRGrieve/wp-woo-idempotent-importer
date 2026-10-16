<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Store;

use IdempotentShopifyImporter\Model\Kind;

/** One Shopify object ↔ the WordPress object it was written to, and the hash of what was written. */
final readonly class Link
{
    public function __construct(
        public Kind $kind,
        public string $gid,
        public int $targetId,
        public string $hash,
    ) {}
}
