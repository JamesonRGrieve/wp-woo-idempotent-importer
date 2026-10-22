<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho\Mapping;

use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A deal → a FluentBoards task on its pipeline's board, in its stage: its name, description, amount (its value) in
 * its currency (the org's when it has one currency), closing date (the due date), won or lost (`stage_outcome`,
 * which the reader adds from its stage's forecast type), its contact (Contact_Name, the FluentBoards contact), its account (meta)
 * and its owner. Every other field a user can set is meta, and so are the org's own fields, by API name.
 */
final class DealMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = ['Deal_Name', 'Description', 'Stage', 'Pipeline', 'Amount', 'Currency', 'Closing_Date', 'Contact_Name', 'Account_Name', 'Owner'];

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
        $stage = Fields::text($node, 'Stage') ?? '';
        $outcome = $node->optionalString('stage_outcome');
        $contact = Fields::lookupId($node, 'Contact_Name');
        $account = Fields::lookupId($node, 'Account_Name');
        $closingDate = Fields::text($node, 'Closing_Date');

        return BoardContract::card(
            Kind::CrmDeal,
            $node->id(),
            ['gid' => Fields::id('Pipeline', $node->string('pipeline_id')), 'stage' => $stage],
            Fields::text($node, 'Deal_Name') ?? '',
            CrmContract::textHtml(Fields::text($node, 'Description')),
            $outcome,
            $outcome !== null,
            Fields::text($node, 'Amount'),
            Fields::text($node, 'Currency') ?? $node->optionalString('org_currency'),
            $closingDate,
            null,
            $contact === null ? [] : [Fields::id('Contact', $contact)],
            Fields::owner($node),
            Fields::time($node, 'Created_Time'),
            $outcome === null ? null : $closingDate,
            [
                'company_gids' => $account === null ? [] : [Fields::id('Account', $account)],
                'deal' => Fields::details($node, self::NATIVE),
                'custom' => Fields::customValues($node),
            ],
        );
    }
}
