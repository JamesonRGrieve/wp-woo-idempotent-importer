<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive\Mapping;

use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * An activity → a FluentBoards task on the activity board, to do or done (when it was marked done): its subject,
 * its note with its type, duration, location and conference link listed after it, its due date (and time, which
 * Pipedrive keeps in UTC), its persons (its own first, then its participants; the first is the FluentBoards
 * contact), its organization (meta) and its owner. A done activity is also a FluentCRM note on its people (the
 * activity log); here it is the task that was done. Its deal, lead, priority and busy flag are meta. A deleted
 * activity retires its task.
 */
final class TaskMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['type', 'deal_id', 'lead_id', 'project_id', 'priority', 'outcome', 'busy', 'duration', 'public_description', 'attendees'];

    public function kind(): Kind
    {
        return Kind::CrmTask;
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
        $done = ($node->raw()['done'] ?? false) === true;
        $date = $node->optionalString('due_date');
        $time = $node->optionalString('due_time');
        $people = [$node->optionalInt('person_id'), ...array_map(static fn(Node $participant): ?int => $participant->optionalInt('person_id'), is_array($node->raw()['participants'] ?? null) ? $node->list('participants') : [])];
        $organization = $node->optionalInt('org_id');

        return BoardContract::card(
            Kind::CrmTask,
            $node->id(),
            ['gid' => Fields::id('ActivityBoard', 'activities'), 'stage' => $done ? BoardMapper::DONE : BoardMapper::TO_DO],
            $node->optionalString('subject') ?? ucfirst($node->optionalString('type') ?? 'activity'),
            ($node->optionalString('note') ?? '') . CrmContract::detailsHtml([
                'Type' => $node->optionalString('type'),
                'Duration' => $node->optionalString('duration'),
                'Location' => $node->child('location')?->optionalString('value'),
                'Conference' => $node->optionalString('conference_meeting_url'),
            ]),
            null,
            $done,
            null,
            null,
            $date === null ? null : ($time === null || $time === '' ? $date : "{$date}T{$time}:00Z"),
            null,
            array_map(static fn(int $id): string => Fields::id('Person', $id), array_values(array_unique(array_filter($people, static fn(?int $id): bool => $id !== null)))),
            Fields::owner($node),
            Fields::time($node, 'add_time'),
            Fields::time($node, 'marked_as_done_time'),
            [
                'company_gids' => $organization === null ? [] : [Fields::id('Organization', $organization)],
                'activity' => array_filter($node->only(self::DETAILS), static fn(mixed $value): bool => $value !== null && $value !== [] && $value !== ''),
            ],
        );
    }
}
