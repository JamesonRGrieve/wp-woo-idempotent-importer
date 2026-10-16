<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

/** One page of a root connection, every node complete. */
final readonly class Page
{
    /** @param list<Node> $nodes */
    public function __construct(
        public array $nodes,
        public bool $hasNextPage,
        public ?string $endCursor,
    ) {}
}
