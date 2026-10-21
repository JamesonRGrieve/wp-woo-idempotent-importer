<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A person filter (a saved segment) → a FluentCRM list of the persons it matches, mirrored each run, so the list
 * follows Pipedrive's segment. Its owner, visibility and system code are meta; its conditions, which FluentCRM's
 * free lists can't evaluate, are evaluated by Pipedrive.
 */
final class FilterMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['filter_code', 'user_id', 'visible_to', 'custom_view_id'];

    public function kind(): Kind
    {
        return Kind::CrmList;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        return new Desired(Kind::CrmList, $node->id(), [
            'title' => $node->string('name'),
            'description' => null,
            'filter' => array_filter($node->only(self::DETAILS), static fn(mixed $value): bool => $value !== null),
        ]);
    }
}
