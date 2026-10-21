<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A logged call or email (a task whose subtype is Call, Email or ListEmail), an event, or a note → a FluentCRM
 * note on its contact's or lead's profile (`WhoId`, a note's `ParentId`) or, failing that, its account's
 * (`WhatId`): a call, email, meeting or note, its subject or title, its description or body, and when it
 * happened (an event's start, else when it was created). A task's status, priority, due date, call type,
 * duration and result, and an event's times and location are listed after the body. Its owner and the other
 * records it is related to are meta.
 */
final class ActivityMapper implements Mapper
{
    private const VERSION = 1;
    private const TASK_TYPES = ['Call' => CrmContract::CALL, 'Email' => CrmContract::EMAIL, 'ListEmail' => CrmContract::EMAIL];

    public function kind(): Kind
    {
        return Kind::CrmNote;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $object = $node->requiredChild('attributes')->string('type');
        [$type, $title, $body, $details, $at] = match ($object) {
            'Task' => [
                self::TASK_TYPES[$node->optionalString('TaskSubtype') ?? ''] ?? CrmContract::NOTE,
                Fields::text($node, 'Subject'),
                Fields::text($node, 'Description'),
                ['Status' => Fields::text($node, 'Status'), 'Priority' => Fields::text($node, 'Priority'), 'Due' => Fields::text($node, 'ActivityDate'),
                    'Call type' => Fields::text($node, 'CallType'), 'Duration (seconds)' => Fields::text($node, 'CallDurationInSeconds'), 'Result' => Fields::text($node, 'CallDisposition')],
                Fields::time($node, 'CreatedDate'),
            ],
            'Event' => [
                CrmContract::MEETING,
                Fields::text($node, 'Subject'),
                Fields::text($node, 'Description'),
                ['Starts' => Fields::text($node, 'StartDateTime'), 'Ends' => Fields::text($node, 'EndDateTime'), 'Location' => Fields::text($node, 'Location')],
                Fields::time($node, 'StartDateTime') ?? Fields::time($node, 'CreatedDate'),
            ],
            default => [CrmContract::NOTE, Fields::text($node, 'Title'), Fields::text($node, 'Body'), [], Fields::time($node, 'CreatedDate')],
        };
        $ownerType = $node->string('owner_type');
        $ownerGid = Fields::id($ownerType, $node->string('owner_id'));

        return new Desired(Kind::CrmNote, $node->id(), [
            'contact_gid' => $ownerType === 'Account' ? null : $ownerGid,
            'company_gid' => $ownerType === 'Account' ? $ownerGid : null,
            'type' => $type,
            'title' => $title ?? ucfirst($type),
            'description_html' => CrmContract::textHtml($body) . CrmContract::detailsHtml($details),
            'created_at' => $at ?? Fields::time($node, 'CreatedDate'),
            'owner_id' => Fields::text($node, 'OwnerId'),
            'related_to' => Fields::text($node, 'WhatId'),
        ]);
    }
}
