<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Mapping;

use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Shopify\ShopifyNode;

/**
 * Pure translation of one Shopify object into its desired WordPress state. Bump version() whenever the
 * mapping changes, so every object of the kind is rewritten on the next run.
 */
interface Mapper
{
    public function kind(): Kind;

    public function version(): int;

    /**
     * @return Desired|null null when the object should not exist on the target (e.g. a completed draft
     *                      order), so an earlier copy is retired
     *
     * @throws Unsupported when the target has no schema for the object
     */
    public function map(ShopifyNode $node): ?Desired;
}
