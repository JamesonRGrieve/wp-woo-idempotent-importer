<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Stage;
use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\Fluent\FluentBoards;
use IdempotentImporter\Wp\Fluent\FluentBoardsMeta;
use IdempotentImporter\Wp\Meta\MetaSync;

/**
 * CRM pipelines (and a source's task list) → FluentBoards boards: the title and currency, and the stages in their
 * order, each a FluentBoards stage whose tasks are open, or closed for a won or lost stage. Which stage is which is
 * kept on the board (STAGES_META, stage key → stage id), so a renamed stage stays the same stage; a stage the
 * source no longer has is archived, as FluentBoards archives one. Stage probabilities and outcomes are meta (the
 * stage definitions). A retired board is archived.
 */
final class CrmPipelineWriter implements Writer
{
    public const STAGES_META = '_iim_stage_ids';
    private const CONSUMED = ['title', 'currency', 'created_at'];
    private const STAGE_TITLE_LENGTH = 100;

    public function kind(): Kind
    {
        return Kind::CrmPipeline;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        FluentBoards::require();
        $f = Values::of($desired);
        $board = ($existing === null ? null : FluentBoards::board($existing->targetId)) ?? new Board();
        $attributes = [
            'title' => $f->string('title'),
            'type' => FluentBoards::BOARD_TYPE,
            'currency' => $f->nullableString('currency'),
            'archived_at' => null,
        ];
        if (FluentBoards::id($board) === 0) {
            $attributes['created_by'] = get_current_user_id();
        }
        FluentBoards::fill($board, $attributes, [], ['background' => []]);
        $id = FluentBoards::id($board);
        $meta = new FluentBoardsMeta(Kind::CrmPipeline, $id);
        $meta->set(self::STAGES_META, $this->stages($id, $f->records('stages'), self::stageIds($meta->get(self::STAGES_META))));
        MetaSync::mirror($meta, $desired->gid, $f, self::CONSUMED);

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return FluentBoards::isActive() && FluentBoards::board($targetId) !== null;
    }

    public function retire(Link $link): void
    {
        $board = FluentBoards::board($link->targetId);
        if ($board !== null) {
            FluentBoards::fill($board, ['archived_at' => current_time('mysql')]);
        }
        MetaSync::fields(new FluentBoardsMeta(Kind::CrmPipeline, $link->targetId), [MetaSync::DELETED => '1']);
    }

    /** The stage id of a stage key on a mirrored board, or UnresolvedDependency when the board doesn't have it (yet). */
    public static function stageId(int $boardId, string $key): int
    {
        return self::stageIds((new FluentBoardsMeta(Kind::CrmPipeline, $boardId))->get(self::STAGES_META))[$key]
            ?? throw new UnresolvedDependency("board {$boardId} has no stage {$key} yet.");
    }

    /**
     * Writes the board's stages in order and archives those it no longer has.
     *
     * @param list<Values> $stages
     * @param array<string, int> $known stage key → stage id
     *
     * @return array<string, int> stage key → stage id, the board's stages now
     */
    private function stages(int $boardId, array $stages, array $known): array
    {
        $ids = [];
        foreach ($stages as $position => $stage) {
            $key = $stage->string('key');
            $model = (isset($known[$key]) ? FluentBoards::stage($known[$key]) : null) ?? new Stage();
            $title = mb_substr($stage->string('title'), 0, self::STAGE_TITLE_LENGTH);
            FluentBoards::fill($model, [
                'board_id' => $boardId,
                'title' => $title,
                'slug' => sanitize_title($title),
                'type' => FluentBoards::STAGE_TYPE,
                'position' => number_format($position + 1, 2, '.', ''),
                'archived_at' => null,
            ], ['settings' => ['default_task_status' => $stage->bool('closed') ? BoardContract::CLOSED : BoardContract::OPEN, 'is_template' => false]]);
            $ids[$key] = FluentBoards::id($model);
        }
        foreach (array_diff_key($known, $ids) as $gone) {
            $model = FluentBoards::stage($gone);
            if ($model !== null) {
                FluentBoards::fill($model, ['archived_at' => current_time('mysql')]);
            }
        }

        return $ids;
    }

    /** @return array<string, int> */
    private static function stageIds(mixed $stored): array
    {
        $ids = [];
        foreach (is_array($stored) ? $stored : [] as $key => $id) {
            if (is_numeric($id)) {
                $ids[(string) $key] = (int) $id;
            }
        }

        return $ids;
    }
}
