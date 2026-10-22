<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use FluentBoards\App\Models\Task;
use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\Fluent\FluentBoards;
use IdempotentImporter\Wp\Fluent\FluentBoardsMeta;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\OwnerUsers;

/**
 * CRM deals and tasks → FluentBoards tasks on their board (BoardContract): the title, HTML description, stage,
 * status (closed when won, lost or completed, with when), value (`lead_value`), due date, priority, and the first
 * contact as the task's FluentCRM contact (FluentBoards links one; the rest are meta). The task names the importer
 * as its source and its identity as its source id. The owner is the task's assignee only when exactly one WordPress
 * user has the owner's email (OwnerUsers); otherwise the task keeps the owner's id (meta) and the log says so. A
 * task keeps its place in its stage until it moves stage. A retired task is archived.
 */
final class BoardTaskWriter implements Writer
{
    private const CONSUMED = ['board_gid', 'stage_key', 'title', 'description_html', 'status', 'lead_value', 'due_at', 'priority', 'created_at', 'completed_at'];
    /** FluentBoards' `lead_value` of a task with none. */
    private const NO_VALUE = '0.00';
    private const DEFAULT_SETTINGS = ['cover' => ['backgroundColor' => ''], 'subtask_count' => 0, 'attachment_count' => 0, 'subtask_completed_count' => 0];

    public function __construct(private readonly Kind $kind, private readonly Targets $targets, private readonly OwnerUsers $owners) {}

    public function kind(): Kind
    {
        return $this->kind;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        FluentBoards::require();
        $f = Values::of($desired);
        $boardId = $this->targets->require(Kind::CrmPipeline, $f->string('board_gid'));
        $stageId = CrmPipelineWriter::stageId($boardId, $f->string('stage_key'));
        $task = ($existing === null ? null : FluentBoards::task($existing->targetId)) ?? new Task();
        $new = FluentBoards::id($task) === 0;
        $moved = $new || (int) FluentBoards::string($task, 'stage_id') !== $stageId;
        $closed = $f->string('status') === BoardContract::CLOSED;
        $attributes = [
            'board_id' => $boardId,
            'stage_id' => $stageId,
            'title' => $f->string('title'),
            'slug' => sanitize_title($f->string('title')),
            'type' => FluentBoards::TASK_TYPE,
            'status' => $closed ? BoardContract::CLOSED : BoardContract::OPEN,
            'source' => FluentBoards::TASK_SOURCE,
            'source_id' => $desired->gid,
            'crm_contact_id' => $this->contactId($f->strings('contact_gids')),
            'priority' => $f->nullableString('priority'),
            'description' => $f->string('description_html'),
            'lead_value' => $f->nullableString('lead_value') ?? self::NO_VALUE,
            'due_at' => FluentBoards::localTime($f->nullableString('due_at')),
            'last_completed_at' => $closed ? FluentBoards::localTime($f->nullableString('completed_at')) : null,
            'archived_at' => null,
        ];
        if ($moved) {
            $attributes['position'] = FluentBoards::nextPosition($stageId);
        }
        $createdAt = FluentBoards::localTime($f->nullableString('created_at'));
        if ($new) {
            $attributes['created_by'] = get_current_user_id();
            if ($createdAt !== null) {
                $attributes['created_at'] = $createdAt;
            }
        }
        FluentBoards::fill($task, $attributes, [], ['settings' => self::DEFAULT_SETTINGS]);
        $id = FluentBoards::id($task);
        FluentBoards::syncAssignees($task, $boardId, $this->assignees($desired->gid, $f->record('owner')));
        MetaSync::mirror(new FluentBoardsMeta($this->kind, $id), $desired->gid, $f, self::CONSUMED);

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return FluentBoards::isActive() && FluentBoards::task($targetId) !== null;
    }

    public function retire(Link $link): void
    {
        $task = FluentBoards::task($link->targetId);
        if ($task !== null) {
            FluentBoards::fill($task, ['archived_at' => current_time('mysql')]);
        }
        MetaSync::fields(new FluentBoardsMeta($this->kind, $link->targetId), [MetaSync::DELETED => '1']);
    }

    /**
     * The FluentCRM contact of the first contact, when contacts are mirrored (it must be, then); none otherwise.
     *
     * @param list<string> $gids
     */
    private function contactId(array $gids): ?int
    {
        if ($gids === [] || !$this->targets->settings->syncs(Kind::Contact)) {
            return null;
        }

        return $this->targets->require(Kind::Contact, $gids[0]);
    }

    /** @return list<int> the WordPress user the owner cleanly matches, or none (said in the log) */
    private function assignees(string $gid, ?Values $owner): array
    {
        if ($owner === null) {
            return [];
        }
        $userId = $this->owners->userId($owner->nullableString('email'));
        if ($userId === null) {
            $email = $owner->nullableString('email');
            $this->targets->log->warning("{$gid}: owner {$owner->string('id')}" . ($email === null ? ' has no email address' : " ({$email}) is not exactly one WordPress user's email") . ', so the task has no assignee and keeps the owner as its id.');

            return [];
        }

        return [$userId];
    }
}
