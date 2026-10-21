<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A note, or a done activity, → a FluentCRM note on each person and organization it belongs to (the reader gives
 * each its own identity, with `belongs_to_type` and `belongs_to_id`). A note is a note with its HTML content, dated when it
 * was added. An activity of type `call`, `email` or `meeting` (`lunch` too) is a call, email or meeting note, any
 * other type a note; its subject is the title, its note the body, and its type, due date and time, duration,
 * location, conference link and public description are listed after it; it is dated when it was marked done.
 * Its owner, deal and lead are meta.
 */
final class ActivityMapper implements Mapper
{
    private const VERSION = 1;
    private const TYPES = ['call' => CrmContract::CALL, 'email' => CrmContract::EMAIL, 'meeting' => CrmContract::MEETING, 'lunch' => CrmContract::MEETING];

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
        $isNote = $node->string('record') === 'note';
        $ownerGid = Fields::id($node->string('belongs_to_type'), $node->int('belongs_to_id'));
        $person = $node->string('belongs_to_type') === 'Person';
        if ($isNote) {
            $fields = [
                'type' => CrmContract::NOTE,
                'title' => 'Note',
                'description_html' => $node->optionalString('content') ?? '',
                'created_at' => Times::requiredIso($node, 'add_time'),
            ];
        } else {
            $type = $node->string('type');
            $fields = [
                'type' => self::TYPES[$type] ?? CrmContract::NOTE,
                'title' => $node->optionalString('subject') ?? ucfirst($type),
                'description_html' => ($node->optionalString('note') ?? '') . CrmContract::detailsHtml([
                    'Type' => $type,
                    'Due' => trim(($node->optionalString('due_date') ?? '') . ' ' . ($node->optionalString('due_time') ?? '')),
                    'Duration' => $node->optionalString('duration'),
                    'Location' => $node->child('location')?->optionalString('value'),
                    'Conference' => $node->optionalString('conference_meeting_url'),
                    'Public description' => $node->optionalString('public_description'),
                ]),
                'created_at' => Fields::time($node, 'marked_as_done_time') ?? Times::requiredIso($node, 'add_time'),
            ];
        }

        return new Desired(Kind::CrmNote, $node->id(), [
            'contact_gid' => $person ? $ownerGid : null,
            'company_gid' => $person ? null : $ownerGid,
            ...$fields,
            'owner_id' => $node->optionalInt('owner_id') ?? $node->optionalInt('user_id'),
            'deal_id' => $node->optionalInt('deal_id'),
            'lead_id' => $node->optionalString('lead_id'),
        ]);
    }
}
