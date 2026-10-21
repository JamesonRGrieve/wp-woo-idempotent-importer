<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use RuntimeException;

/**
 * The source object is a copy of something WooCommerce itself holds (an order WooCommerce Square pushed to
 * Square, read back): mirroring it would duplicate the original, so it is left alone. Not a failure.
 */
final class OriginalInWooCommerce extends RuntimeException {}
