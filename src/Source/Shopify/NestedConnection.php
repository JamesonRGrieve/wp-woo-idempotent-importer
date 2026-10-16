<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

/**
 * A connection on a top-level node that can hold more than one page (e.g. Product.variants). Its
 * selection is a connection-level fragment, shared by the main query and the follow-up page query.
 */
final readonly class NestedConnection
{
    public function __construct(
        public string $field,
        public string $fragment,
        public string $extraArguments = '',
    ) {}
}
