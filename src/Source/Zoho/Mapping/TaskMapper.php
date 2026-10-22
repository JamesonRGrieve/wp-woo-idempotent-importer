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
use IdempotentImporter\Source\Zoho\ZohoReader;

/**
 * A task → a FluentBoards task on the Zoho CRM task board, in the stage of its status: its subject, description,
 * due date, priority (Highest and High are high, Normal medium, Low and Lowest low; Highest is urgent), completion
 * (status Completed, when it closed), the contact it is about (Who_Id, the FluentBoards contact), its account (meta,
 * when it relates to one) and its owner. What it relates to and every other field a user can set are meta, and so
 * are the org's own fields, by API name.
 */
final class TaskMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = ['Subject', 'Description', ZohoReader::TASK_STATUS, 'Priority', 'Due_Date', 'Closed_Time', 'Who_Id', 'Owner'];
    private const PRIORITIES = [
        'Highest' => BoardContract::URGENT, 'High' => BoardContract::HIGH, 'Normal' => BoardContract::MEDIUM, 'Low' => BoardContract::LOW, 'Lowest' => BoardContract::LOW,
    ];

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
        $status = Fields::text($node, ZohoReader::TASK_STATUS) ?? '';
        $contact = Fields::lookupId($node, 'Who_Id');
        $relatedTo = Fields::lookupId($node, 'What_Id');

        return BoardContract::card(
            Kind::CrmTask,
            $node->id(),
            ['gid' => Fields::id('TaskBoard', 'tasks'), 'stage' => $status],
            Fields::text($node, 'Subject') ?? '',
            CrmContract::textHtml(Fields::text($node, 'Description')),
            null,
            $status === BoardMapper::COMPLETED,
            null,
            null,
            Fields::text($node, 'Due_Date'),
            self::PRIORITIES[Fields::text($node, 'Priority') ?? ''] ?? null,
            $contact === null ? [] : [Fields::id('Contact', $contact)],
            Fields::owner($node),
            Fields::time($node, 'Created_Time'),
            Fields::time($node, 'Closed_Time'),
            [
                'company_gids' => $relatedTo !== null && $node->optionalString('$se_module') === 'Accounts' ? [Fields::id('Account', $relatedTo)] : [],
                'task' => Fields::details($node, self::NATIVE),
                'custom' => Fields::customValues($node),
            ],
        );
    }
}
