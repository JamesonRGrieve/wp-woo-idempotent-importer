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
 * A task (one that isn't a logged call or email, which are notes) → a FluentBoards task on the task board, in the
 * stage of its status: its subject, description, due date (ActivityDate), priority (High, Normal, Low), whether it
 * is closed (IsClosed, with CompletedDateTime), the contact or lead it is about (WhoId), its account (meta) and its
 * owner. The record it is related to (WhatId) and every other field a user can set are meta, and so are the org's
 * own fields, by name.
 */
final class TaskMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = ['Subject', 'Description', 'Status', 'ActivityDate', 'Priority', 'IsClosed', 'WhoId', 'AccountId', 'OwnerId'];
    private const PRIORITIES = ['High' => BoardContract::HIGH, 'Normal' => BoardContract::MEDIUM, 'Low' => BoardContract::LOW];

    public function kind(): Kind
    {
        return Kind::CrmTask;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $who = Fields::text($node, 'WhoId');
        $whoType = Fields::ownerType($who);
        $account = Fields::text($node, 'AccountId');

        return BoardContract::card(
            Kind::CrmTask,
            $node->id(),
            ['gid' => Fields::id('Board', BoardMapper::TASKS), 'stage' => Fields::text($node, 'Status') ?? ''],
            Fields::text($node, 'Subject') ?? '',
            CrmContract::textHtml(Fields::text($node, 'Description')),
            null,
            Fields::flag($node, 'IsClosed'),
            null,
            null,
            Fields::text($node, 'ActivityDate'),
            self::PRIORITIES[Fields::text($node, 'Priority') ?? ''] ?? null,
            $who !== null && in_array($whoType, ['Contact', 'Lead'], true) ? [Fields::id($whoType, $who)] : [],
            Fields::owner($node),
            Fields::time($node, 'CreatedDate'),
            Fields::time($node, 'CompletedDateTime'),
            [
                'company_gids' => $account === null ? [] : [Fields::id('Account', $account)],
                'task' => Fields::details($node, self::NATIVE),
                'custom' => Fields::customValues($node),
            ],
        );
    }
}
