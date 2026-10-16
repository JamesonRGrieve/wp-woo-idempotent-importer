<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use IdempotentImporter\Source\SourceError;

/** Shopify answered, but the app lacks an access scope a field in the query needs (error code ACCESS_DENIED). */
final class AccessDenied extends SourceError {}
