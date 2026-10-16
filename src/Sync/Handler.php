<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Sync;

use IdempotentShopifyImporter\Mapping\Mapper;
use IdempotentShopifyImporter\Wp\Writer\Writer;

/** One kind's pure mapping and its WordPress writer. */
final readonly class Handler
{
    public function __construct(public Mapper $mapper, public Writer $writer) {}
}
