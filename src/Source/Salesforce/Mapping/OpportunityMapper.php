<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce\Mapping;

use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * An opportunity → a FluentBoards task on the opportunity board, in the stage its StageName names: its name,
 * description, amount (its value) in its currency (the org's default when it has no multiple currencies), close
 * date (the due date, and when it closed), won or lost (IsClosed, IsWon), its contacts by role (the primary first:
 * the FluentBoards contact), its account (meta) and its owner. Every other field a user can set is meta, and so are
 * the org's own fields, by name.
 */
final class OpportunityMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = ['Name', 'Description', 'StageName', 'Amount', 'CurrencyIsoCode', 'CloseDate', 'IsClosed', 'IsWon', 'AccountId', 'OwnerId'];

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
        $closed = Fields::flag($node, 'IsClosed');
        $closeDate = Fields::text($node, 'CloseDate');
        $roles = $node->child('OpportunityContactRoles')?->list('records') ?? [];
        usort($roles, static fn(Node $a, Node $b): int => (int) Fields::flag($b, 'IsPrimary') <=> (int) Fields::flag($a, 'IsPrimary'));
        $account = Fields::text($node, 'AccountId');

        return BoardContract::card(
            Kind::CrmDeal,
            $node->id(),
            ['gid' => Fields::id('Board', BoardMapper::OPPORTUNITIES), 'stage' => Fields::text($node, 'StageName') ?? ''],
            Fields::text($node, 'Name') ?? '',
            CrmContract::textHtml(Fields::text($node, 'Description')),
            $closed ? (Fields::flag($node, 'IsWon') ? BoardContract::WON : BoardContract::LOST) : null,
            $closed,
            Fields::text($node, 'Amount'),
            Fields::text($node, 'CurrencyIsoCode') ?? $node->optionalString('org_currency'),
            $closeDate,
            null,
            array_values(array_filter(array_map(static fn(Node $role): ?string => ($id = Fields::text($role, 'ContactId')) === null ? null : Fields::id('Contact', $id), $roles))),
            Fields::owner($node),
            Fields::time($node, 'CreatedDate'),
            $closeDate,
            [
                'company_gids' => $account === null ? [] : [Fields::id('Account', $account)],
                'opportunity' => Fields::details($node, self::NATIVE),
                'custom' => Fields::customValues($node),
            ],
        );
    }
}
