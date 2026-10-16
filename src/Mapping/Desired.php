<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Mapping;

use IdempotentShopifyImporter\Model\Kind;

/**
 * What one Shopify object should look like on the WordPress side: the mapped fields (and only those),
 * the old Shopify URL paths that should 301 to it (or, for a Shopify URL redirect, to $redirectTarget),
 * and anything about it the target can't represent.
 */
final readonly class Desired
{
    /**
     * @param array<string, mixed> $fields
     * @param list<string> $legacyPaths
     * @param list<string> $warnings
     */
    public function __construct(
        public Kind $kind,
        public string $gid,
        public array $fields,
        public array $legacyPaths = [],
        public array $warnings = [],
        public ?string $redirectTarget = null,
    ) {}
}
