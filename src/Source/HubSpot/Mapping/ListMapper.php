<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\HubSpot\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A contact list → a FluentCRM list named after it. Static (manual) lists and active (dynamic) segments alike:
 * each run mirrors who is in the list now onto its contacts, so a segment's membership follows HubSpot's; its
 * filters, which FluentCRM's free lists can't evaluate, are kept as meta with its processing type.
 */
final class ListMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['processingType', 'listVersion', 'createdAt', 'filtersUpdatedAt'];

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
            'list' => $node->only(self::DETAILS),
            'filters' => $node->child('filterBranch')?->raw(),
        ]);
    }
}
