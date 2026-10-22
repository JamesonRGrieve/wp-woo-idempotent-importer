<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive\Mapping;

use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A deal → a FluentBoards task on its pipeline's board, in its stage: its title, value in its currency, expected
 * close date (the due date), won or lost (its status, closed when it was won or lost), its person (the FluentBoards
 * contact), its organization (meta) and its owner. Its probability, lost reason, labels, origin, channel, archived
 * flag and custom fields are meta. A deleted deal retires its task.
 */
final class DealMapper implements Mapper
{
    private const VERSION = 1;
    private const OUTCOMES = ['won' => BoardContract::WON, 'lost' => BoardContract::LOST];
    private const DETAILS = ['probability', 'lost_reason', 'label_ids', 'origin', 'origin_id', 'channel', 'channel_id', 'source_lead_id', 'visible_to', 'is_archived', 'arr', 'mrr', 'acv', 'custom_fields'];

    public function kind(): Kind
    {
        return Kind::CrmDeal;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): ?Desired
    {
        if (($node->raw()['is_deleted'] ?? false) === true) {
            return null;
        }
        $outcome = self::OUTCOMES[$node->optionalString('status') ?? ''] ?? null;
        $value = $node->raw()['value'] ?? null;
        $person = $node->optionalInt('person_id');
        $organization = $node->optionalInt('org_id');

        return BoardContract::card(
            Kind::CrmDeal,
            $node->id(),
            ['gid' => Fields::id('Pipeline', $node->int('pipeline_id')), 'stage' => (string) $node->int('stage_id')],
            $node->optionalString('title') ?? '',
            '',
            $outcome,
            $outcome !== null,
            is_int($value) || is_float($value) ? Decimal::of($value) : null,
            $node->optionalString('currency'),
            $node->optionalString('expected_close_date'),
            null,
            $person === null ? [] : [Fields::id('Person', $person)],
            Fields::owner($node),
            Fields::time($node, 'add_time'),
            Fields::time($node, 'won_time') ?? Fields::time($node, 'lost_time') ?? Fields::time($node, 'close_time'),
            [
                'company_gids' => $organization === null ? [] : [Fields::id('Organization', $organization)],
                'deal' => array_filter($node->only(self::DETAILS), static fn(mixed $value): bool => $value !== null && $value !== [] && $value !== ''),
            ],
        );
    }
}
