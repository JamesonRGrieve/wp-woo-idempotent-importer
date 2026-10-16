<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Shopify;

use RuntimeException;

/** A Shopify transport, protocol or data-shape failure. Messages never contain credentials. */
class ShopifyError extends RuntimeException {}
