<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix;

/**
 * Which Wix Stores catalog a site runs (Get Catalog Version): V1 (the original catalog) or V3. They are
 * different APIs with different shapes, so the reader asks once and reads products and their collections
 * or categories from the one the site has. The values are Wix's own.
 */
enum Catalog: string
{
    case V1 = 'V1_CATALOG';
    case V3 = 'V3_CATALOG';
    case None = 'STORES_NOT_INSTALLED';
}
