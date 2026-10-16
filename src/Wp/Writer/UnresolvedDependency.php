<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use RuntimeException;

/** An object needs another that isn't mirrored yet (e.g. an order line's product). Retried, not skipped. */
final class UnresolvedDependency extends RuntimeException {}
