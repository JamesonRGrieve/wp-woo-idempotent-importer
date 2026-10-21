<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A campaign → a FluentCRM list of its members (the contacts and leads in its related lists): its name and
 * description. Its type, status, dates, budget and results are meta.
 */
final class CampaignMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = ['Campaign_Name', 'Description'];

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
            'title' => Fields::text($node, 'Campaign_Name') ?? 'Campaign ' . Fields::sourceId($node),
            'description' => Fields::text($node, 'Description'),
            'campaign' => Fields::details($node, self::NATIVE),
        ]);
    }
}
