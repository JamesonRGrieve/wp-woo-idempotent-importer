<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use RuntimeException;

/** A source object WordPress + WooCommerce has no schema for (a gap in coverage/<source>.md). Reported, not fatal. */
final class Unsupported extends RuntimeException {}
