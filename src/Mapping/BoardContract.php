<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Support\Decimal;

/**
 * The shared field contract of CRM pipelines, deals and tasks (FluentBoards' vocabulary), so every CRM mapper
 * speaks it the same way. A pipeline (or a source's task list) is a board with its stages, in order; a deal or a
 * task is a card on a board, in one of its stages, linked to its first contact (FluentBoards links one FluentCRM
 * contact to a task). CrmPipelineWriter and BoardTaskWriter read what these build.
 *
 * Money is an exact decimal string. FluentBoards keeps a card's value as `lead_value`, DECIMAL(10,2); an amount
 * that column can't hold exactly (more than two decimal places, or 100,000,000 or more) is kept only as meta, with
 * a warning, never rounded; one it can is `lead_value` with its two places ("12.50"). Times are ISO 8601 UTC; a due
 * date the source keeps without a time (a close date) stays a date (`2026-10-15`), that day wherever the site is.
 */
final class BoardContract
{
    /** FluentBoards task statuses: a won or lost deal and a completed task are closed. */
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    /** How a closed deal ended. */
    public const WON = 'won';
    public const LOST = 'lost';
    /** FluentBoards task priorities. */
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';
    public const URGENT = 'urgent';
    /** DECIMAL(10,2): eight digits before the point, two after. */
    private const LEAD_VALUE_PATTERN = '/^-?\d{1,8}(\.\d{1,2})?$/';

    /**
     * A board: a pipeline with its stages in order, or a source's task list with a stage per task status.
     *
     * @param list<array{key: string, title: string, closed: bool, outcome: ?string, probability: ?string}> $stages
     * @param array<string, mixed> $extra fields with no FluentBoards home, kept as meta
     */
    public static function board(string $gid, string $title, ?string $currency, array $stages, ?string $createdAt, array $extra = []): Desired
    {
        if ($stages === []) {
            throw new Unsupported("Board {$title} has no stages.");
        }

        return new Desired(Kind::CrmPipeline, $gid, [
            'title' => $title,
            'currency' => $currency === null ? null : strtoupper($currency),
            'stages' => $stages,
            'created_at' => $createdAt,
            ...$extra,
        ]);
    }

    /** @return array{key: string, title: string, closed: bool, outcome: ?string, probability: ?string} */
    public static function stage(string $key, string $title, bool $closed = false, ?string $outcome = null, ?string $probability = null): array
    {
        return ['key' => $key, 'title' => $title, 'closed' => $closed, 'outcome' => $outcome, 'probability' => $probability];
    }

    /**
     * A deal or a task on a board.
     *
     * @param array{gid: string, stage: string} $placement the board's identity and the stage's key
     * @param list<string> $contactGids the first is the FluentBoards task's contact; the others are meta
     * @param array{id: string, email: ?string, name: ?string}|null $owner the source user it belongs to
     * @param array<string, mixed> $extra fields with no FluentBoards home (companies, probability, the source's
     *                                    own fields), kept as meta
     */
    public static function card(
        Kind $kind,
        string $gid,
        array $placement,
        string $title,
        string $descriptionHtml,
        ?string $outcome,
        bool $closed,
        ?string $amount,
        ?string $currency,
        ?string $dueAt,
        ?string $priority,
        array $contactGids,
        ?array $owner,
        ?string $createdAt,
        ?string $completedAt,
        array $extra = [],
    ): Desired {
        $warnings = [];
        $leadValue = null;
        if ($amount !== null) {
            $amount = Decimal::normalise($amount);
            if (preg_match(self::LEAD_VALUE_PATTERN, $amount) === 1) {
                [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
                $leadValue = $whole . '.' . str_pad($fraction, 2, '0');
            } else {
                $warnings[] = "its amount {$amount} doesn't fit FluentBoards' value (two decimal places, under 100,000,000), so it is kept as meta only.";
            }
        }

        return new Desired($kind, $gid, [
            'board_gid' => $placement['gid'],
            'stage_key' => $placement['stage'],
            'title' => trim($title) === '' ? '(untitled)' : trim($title),
            'description_html' => $descriptionHtml,
            'status' => $closed ? self::CLOSED : self::OPEN,
            'outcome' => $outcome,
            'lead_value' => $leadValue,
            'amount' => $amount,
            'currency' => $currency === null ? null : strtoupper($currency),
            'due_at' => $dueAt,
            'priority' => $priority,
            'contact_gids' => array_values(array_unique($contactGids)),
            'owner' => $owner,
            'created_at' => $createdAt,
            'completed_at' => $closed ? $completedAt : null,
            ...$extra,
        ], [], $warnings);
    }

    /**
     * A source user as a card's owner, or null when there is none.
     *
     * @return array{id: string, email: ?string, name: ?string}|null
     */
    public static function owner(?string $id, ?string $email, ?string $name): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        $email = $email === null || trim($email) === '' ? null : strtolower(trim($email));

        return ['id' => $id, 'email' => $email, 'name' => $name === null || trim($name) === '' ? null : trim($name)];
    }
}
