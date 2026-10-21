<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A note, call or meeting (event) → a FluentCRM note on the contact's or lead's profile, or the account's (the
 * reader names which, `belongs_to_type` and `belongs_to_id`): a note with its title and content, a call with its
 * subject and description (its type, start, duration and result listed after it), or a meeting with its title
 * and description (its start, end and venue listed after it), dated when it happened (a note when it was
 * created). Its owner and attachments are meta.
 */
final class ActivityMapper implements Mapper
{
    private const VERSION = 1;

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
        [$type, $title, $body, $details, $at] = match ($node->string('module')) {
            'Calls' => [
                CrmContract::CALL,
                Fields::text($node, 'Subject'),
                Fields::text($node, 'Description'),
                ['Type' => Fields::text($node, 'Call_Type'), 'Starts' => Fields::text($node, 'Call_Start_Time'), 'Duration' => Fields::text($node, 'Call_Duration'), 'Result' => Fields::text($node, 'Call_Result')],
                Fields::time($node, 'Call_Start_Time'),
            ],
            'Events' => [
                CrmContract::MEETING,
                Fields::text($node, 'Event_Title'),
                Fields::text($node, 'Description'),
                ['Starts' => Fields::text($node, 'Start_DateTime'), 'Ends' => Fields::text($node, 'End_DateTime'), 'Venue' => Fields::text($node, 'Venue')],
                Fields::time($node, 'Start_DateTime'),
            ],
            default => [CrmContract::NOTE, Fields::text($node, 'Note_Title'), Fields::text($node, 'Note_Content'), [], null],
        };
        $ownerType = $node->string('belongs_to_type');
        $ownerGid = Fields::id($ownerType, $node->string('belongs_to_id'));

        return new Desired(Kind::CrmNote, $node->id(), [
            'contact_gid' => $ownerType === 'Account' ? null : $ownerGid,
            'company_gid' => $ownerType === 'Account' ? $ownerGid : null,
            'type' => $type,
            'title' => $title ?? ucfirst($type),
            'description_html' => CrmContract::textHtml($body) . CrmContract::detailsHtml($details),
            'created_at' => $at ?? Times::requiredIso($node, 'Created_Time'),
            'owner_id' => Fields::lookupId($node, 'Owner'),
            'attachments' => $node->raw()['$attachments'] ?? null,
        ]);
    }
}
