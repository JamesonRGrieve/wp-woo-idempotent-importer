<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

/**
 * A connection on a top-level node that can hold more than one page (e.g. Product.variants). Its
 * selection is a connection-level fragment, shared by the main query and the follow-up page query. In the
 * node's fragment its page size is the variable sizeVariable() (e.g. `variants(first: $variantsFirst)`), so
 * the client can shrink it when Shopify refuses a query as too costly.
 */
final readonly class NestedConnection
{
    private const SIZE_VARIABLE_SUFFIX = 'First';

    public function __construct(
        public string $field,
        public string $fragment,
        public string $extraArguments = '',
    ) {}

    /** The operation variable holding this connection's page size in the node's fragment. */
    public function sizeVariable(): string
    {
        return $this->field . self::SIZE_VARIABLE_SUFFIX;
    }
}
