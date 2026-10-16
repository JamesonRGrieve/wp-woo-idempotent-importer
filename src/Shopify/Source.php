<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Shopify;

use IdempotentImporter\Model\Kind;

/** How one synced kind is read from Shopify: its root connection, node fragment and paging abilities. */
final readonly class Source
{
    public const INCREMENTAL_SORT_KEY = 'UPDATED_AT';

    public const NODE_FIELD = 'node';

    /**
     * @param list<NestedConnection> $nested
     * @param string $nodeField the root field that fetches one object by id
     */
    public function __construct(
        public Kind $kind,
        public string $graphqlType,
        public string $connection,
        public string $fragment,
        public ?string $sortKey,
        public bool $searchable,
        public array $nested = [],
        public string $nodeField = self::NODE_FIELD,
    ) {}

    /** Whether runs can fetch only what changed since the last run (`updated_at:>=` + UPDATED_AT order). */
    public function isIncremental(): bool
    {
        return $this->searchable && $this->sortKey === self::INCREMENTAL_SORT_KEY;
    }

    public function listOperation(): string
    {
        return $this->graphqlType . 'List';
    }

    public function nodeOperation(): string
    {
        return $this->graphqlType . 'Node';
    }

    public function pageOperation(NestedConnection $nested): string
    {
        return $this->graphqlType . ucfirst($nested->field) . 'Page';
    }
}
