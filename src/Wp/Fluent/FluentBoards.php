<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Fluent;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Model;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Constant;
use IdempotentImporter\Wp\Dates;

/**
 * The importer's typed gateway to FluentBoards (free, GPL, by the FluentCRM team; wordpress.org/plugins/fluent-boards),
 * the WordPress home of CRM pipelines, deals and tasks: its models (boards, stages, tasks, and the relations that
 * make a user a task's assignee or a board's member), saved quietly. FluentBoards' model events and API helpers
 * announce a new task, a closed task or a new board member to its notifications, activity log and emails, which
 * must never run for an import (the same rule as Quiet: no email). Only FluentBoards' documented models are used;
 * nothing is written to its tables by hand.
 */
final class FluentBoards
{
    /** The board type FluentBoards shows (its boards' global scope); the importer's boards are always this. */
    public const BOARD_TYPE = 'to-do';
    public const TASK_TYPE = 'task';
    public const STAGE_TYPE = 'stage';
    /** What a mirrored task names as its source (FluentBoards' `source`, with the identity as `source_id`). */
    public const TASK_SOURCE = 'idempotent-importer';
    private const DATE_ONLY = '/^\d{4}-\d{2}-\d{2}$/';
    private const START_OF_DAY = ' 00:00:00';

    /** @throws FluentBoardsMissing when FluentBoards isn't active, so nothing can be written */
    public static function require(): void
    {
        if (!defined('FLUENT_BOARDS') || !class_exists(Task::class)) {
            throw new FluentBoardsMissing('FluentBoards is not active: install and activate FluentBoards (free, wordpress.org/plugins/fluent-boards) to mirror CRM pipelines, deals and tasks.');
        }
    }

    public static function isActive(): bool
    {
        return defined('FLUENT_BOARDS') && class_exists(Task::class);
    }

    /**
     * FluentBoards' timestamps are site-local `Y-m-d H:i:s`. A date without a time (a close date) is that day in the
     * site's time zone, never shifted into another day.
     */
    public static function localTime(?string $iso): ?string
    {
        return match (true) {
            $iso === null => null,
            preg_match(self::DATE_ONLY, $iso) === 1 => $iso . self::START_OF_DAY,
            default => Dates::local($iso),
        };
    }

    public static function board(int $id): ?Board
    {
        $found = (new Board())->newQuery()->find($id);

        return $found instanceof Board ? $found : null;
    }

    public static function stage(int $id): ?Stage
    {
        $found = (new Stage())->newQuery()->find($id);

        return $found instanceof Stage ? $found : null;
    }

    public static function task(int $id): ?Task
    {
        $found = (new Task())->newQuery()->find($id);

        return $found instanceof Task ? $found : null;
    }

    public static function id(Model $model): int
    {
        $id = $model->getKey();

        return is_numeric($id) ? (int) $id : 0;
    }

    public static function string(Model $model, string $attribute): string
    {
        $value = $model->getAttribute($attribute);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Sets the attributes that differ and saves quietly when anything did (or the model is new), so an unchanged
     * model is never re-saved and no FluentBoards event fires.
     *
     * @param array<string, scalar|null> $attributes
     * @param array<string, array<array-key, mixed>> $serialized mapped array attributes (a stage's settings), set when they differ
     * @param array<string, array<array-key, mixed>> $defaults array attributes a new model needs (a board's background)
     */
    public static function fill(Model $model, array $attributes, array $serialized = [], array $defaults = []): void
    {
        $changes = Attributes::changes($model->getAttribute(...), $attributes);
        $new = self::id($model) === 0;
        $arrays = $new ? [...$defaults, ...$serialized] : array_filter($serialized, static fn(array $value, string $name): bool => $model->getAttribute($name) !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changes === [] && $arrays === [] && !$new) {
            return;
        }
        $model->fill($changes);
        foreach ($arrays as $name => $value) {
            $model->setAttribute($name, $value);
        }
        $model->saveQuietly();
    }

    /** The position after the last task in a stage (FluentBoards orders a stage's tasks by position). */
    public static function nextPosition(int $stageId): string
    {
        $last = (new Task())->newQuery()->where('stage_id', $stageId)->max('position');

        return number_format((is_numeric($last) ? (float) $last : 0.0) + 1, 2, '.', '');
    }

    /**
     * Makes the task's assignees exactly these WordPress users, each a member of the task's board (FluentBoards
     * shows a board to its members and its admins), with FluentBoards' notifications off for a membership the
     * importer adds.
     *
     * @param list<int> $userIds
     */
    public static function syncAssignees(Task $task, int $boardId, array $userIds): void
    {
        $taskId = self::id($task);
        $current = self::related(Constant::OBJECT_TYPE_TASK_ASSIGNEE, $taskId);
        $gone = array_values(array_diff($current, $userIds));
        if ($gone !== []) {
            // A query delete: no model event fires.
            self::relations(Constant::OBJECT_TYPE_TASK_ASSIGNEE, $taskId)->whereIn('foreign_id', $gone)->delete();
        }
        foreach (array_diff($userIds, $current) as $userId) {
            self::relate(Constant::OBJECT_TYPE_TASK_ASSIGNEE, $taskId, $userId, null, null);
        }
        $members = self::related(Constant::OBJECT_TYPE_BOARD_USER, $boardId);
        foreach (array_diff($userIds, $members) as $userId) {
            self::relate(Constant::OBJECT_TYPE_BOARD_USER, $boardId, $userId, Constant::BOARD_USER_SETTINGS, array_fill_keys(array_keys(Constant::BOARD_NOTIFICATION_TYPES), false));
        }
    }

    /** @return list<int> the foreign ids (users) related to an object */
    private static function related(string $type, int $objectId): array
    {
        return array_values(array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, self::relations($type, $objectId)->pluck('foreign_id')->all()));
    }

    /** @return \FluentBoards\Framework\Database\Orm\Builder<Relation> */
    private static function relations(string $type, int $objectId): \FluentBoards\Framework\Database\Orm\Builder
    {
        return (new Relation())->newQuery()->where('object_type', $type)->where('object_id', $objectId);
    }

    /**
     * @param array<array-key, mixed>|null $settings
     * @param array<array-key, mixed>|null $preferences
     */
    private static function relate(string $type, int $objectId, int $foreignId, ?array $settings, ?array $preferences): void
    {
        $relation = new Relation();
        $relation->fill(['object_type' => $type, 'object_id' => $objectId, 'foreign_id' => $foreignId]);
        if ($settings !== null) {
            $relation->setAttribute('settings', $settings);
        }
        if ($preferences !== null) {
            $relation->setAttribute('preferences', $preferences);
        }
        $relation->saveQuietly();
    }
}
