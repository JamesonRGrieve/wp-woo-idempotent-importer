<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

/** One recorded Shopify GraphQL exchange: the operation, its variables and the response `data`. */
final readonly class Fixture
{
    /**
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $operation,
        public array $variables,
        public array $data,
    ) {}
}
