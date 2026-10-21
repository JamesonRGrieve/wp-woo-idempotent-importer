<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A campaign → a FluentCRM list of its members (contacts and leads, from campaign members): its name and
 * description. Its type, status, dates, budget and results, and every other field a user can set, are meta.
 */
final class CampaignMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = ['Name', 'Description'];

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
            'title' => $node->string('Name'),
            'description' => Fields::text($node, 'Description'),
            'campaign' => Fields::details($node, self::NATIVE),
        ]);
    }
}
