<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/** A topic (Salesforce's tags on records) → a FluentCRM tag: its name and description. */
final class TopicMapper implements Mapper
{
    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::CrmTag;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        return new Desired(Kind::CrmTag, $node->id(), [
            'title' => $node->string('Name'),
            'description' => Fields::text($node, 'Description'),
        ]);
    }
}
