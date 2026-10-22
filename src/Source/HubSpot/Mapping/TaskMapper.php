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

/**
 * A task → a FluentBoards task on the HubSpot task board, in the stage of its status: its subject, body, due date
 * (`hs_timestamp`), priority, completion (status `COMPLETED`, with its completion date), its first contact (the
 * rest, and its companies, are meta) and its owner. Its type, reminders and the rest are meta.
 */
final class TaskMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = ['hs_task_subject', 'hs_task_body', HubSpotReader::TASK_STATUS, 'hs_task_priority', 'hs_timestamp', 'hs_task_completion_date', HubSpotReader::OWNER_PROPERTY];

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
        $status = Fields::property($node, HubSpotReader::TASK_STATUS) ?? '';
        $body = Fields::property($node, 'hs_task_body');

        return BoardContract::card(
            Kind::CrmTask,
            $node->id(),
            ['gid' => Fields::id('TaskBoard', 'tasks'), 'stage' => $status],
            Fields::property($node, 'hs_task_subject') ?? '',
            $body === null ? '' : (str_contains($body, '<') ? $body : CrmContract::textHtml($body)),
            null,
            $status === PipelineMapper::COMPLETED,
            null,
            null,
            Fields::time($node, 'hs_timestamp'),
            Fields::priority(Fields::property($node, 'hs_task_priority')),
            array_map(static fn(string $id): string => Fields::id('Contact', $id), Fields::associated($node, 'contacts')),
            Fields::owner($node),
            Times::iso($node, 'createdAt'),
            Fields::time($node, 'hs_task_completion_date'),
            [
                'company_gids' => array_map(static fn(string $id): string => Fields::id('Company', $id), Fields::associated($node, 'companies')),
                'task' => Fields::details($node, self::NATIVE),
            ],
        );
    }
}
