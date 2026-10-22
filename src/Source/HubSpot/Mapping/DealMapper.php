<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\HubSpot\Mapping;

use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\HubSpot\HubSpotReader;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A deal → a FluentBoards task on its pipeline's board, in its stage: its name, description, amount (its value) in
 * its currency, close date (the due date, and when it closed), priority, its first contact (the rest, and its
 * companies, are meta), its owner, and whether it closed won or lost (HubSpot's `hs_is_closed_won` and
 * `hs_is_closed`). Every other property the account can set is meta, and so are the account's own deal properties,
 * by name (FluentBoards has no custom fields in its free version).
 */
final class DealMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = [
        'dealname', 'description', 'pipeline', 'dealstage', 'amount', 'deal_currency_code', 'closedate', 'hs_priority', 'createdate',
        HubSpotReader::OWNER_PROPERTY,
    ];
    private const TRUE = 'true';

    public function kind(): Kind
    {
        return Kind::CrmDeal;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $closed = Fields::property($node, 'hs_is_closed') === self::TRUE;
        $won = Fields::property($node, 'hs_is_closed_won') === self::TRUE;
        $amount = Fields::property($node, 'amount');
        $closeDate = Fields::time($node, 'closedate');
        $description = Fields::property($node, 'description');

        return BoardContract::card(
            Kind::CrmDeal,
            $node->id(),
            ['gid' => Fields::id('Pipeline', Fields::property($node, 'pipeline') ?? 'default'), 'stage' => Fields::property($node, 'dealstage') ?? ''],
            Fields::property($node, 'dealname') ?? '',
            $description === null ? '' : (str_contains($description, '<') ? $description : CrmContract::textHtml($description)),
            $closed ? ($won ? BoardContract::WON : BoardContract::LOST) : null,
            $closed,
            $amount === null ? null : Decimal::of($amount),
            Fields::property($node, 'deal_currency_code'),
            $closeDate,
            Fields::priority(Fields::property($node, 'hs_priority')),
            array_map(static fn(string $id): string => Fields::id('Contact', $id), Fields::associated($node, 'contacts')),
            Fields::owner($node),
            Fields::time($node, 'createdate') ?? Times::iso($node, 'createdAt'),
            $closeDate,
            [
                'company_gids' => array_map(static fn(string $id): string => Fields::id('Company', $id), Fields::associated($node, 'companies')),
                'deal' => Fields::details($node, self::NATIVE),
                'custom' => Fields::customValues($node),
            ],
        );
    }
}
