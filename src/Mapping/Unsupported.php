<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Mapping;

use RuntimeException;

/** A Shopify object WordPress + WooCommerce has no schema for (a COVERAGE.md gap). Reported, not fatal. */
final class Unsupported extends RuntimeException {}
