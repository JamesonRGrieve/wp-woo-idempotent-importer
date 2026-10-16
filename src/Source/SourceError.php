<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

use RuntimeException;

/** A source platform's transport, protocol or data-shape failure. Messages never contain credentials. */
class SourceError extends RuntimeException {}
