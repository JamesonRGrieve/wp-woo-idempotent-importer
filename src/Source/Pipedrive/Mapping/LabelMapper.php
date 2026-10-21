<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/** A person label (an option of the person label field) → a FluentCRM tag: its label; its colour is meta. */
final class LabelMapper implements Mapper
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
            'title' => $node->string('label'),
            'description' => null,
            'color' => $node->optionalString('color'),
        ]);
    }
}
