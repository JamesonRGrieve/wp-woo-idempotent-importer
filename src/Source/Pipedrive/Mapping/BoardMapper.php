<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive\Mapping;

use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A pipeline → a FluentBoards board with its stages in their order (deleted ones left out, so the board archives
 * them), each with its deal probability. Pipedrive keeps won and lost as a deal's status rather than as stages, so
 * no stage closes its deals; a deal closes when it is won or lost. A deleted pipeline retires its board.
 *
 * The activity board holds the company's activities: to do, and done.
 */
final class BoardMapper implements Mapper
{
    private const VERSION = 1;
    public const TO_DO = 'todo';
    public const DONE = 'done';
    private const ACTIVITY_BOARD_TITLE = 'Pipedrive activities';

    public function kind(): Kind
    {
        return Kind::CrmPipeline;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): ?Desired
    {
        if (str_contains($node->id(), '/ActivityBoard/')) {
            return BoardContract::board($node->id(), self::ACTIVITY_BOARD_TITLE, null, [BoardContract::stage(self::TO_DO, 'To do'), BoardContract::stage(self::DONE, 'Done', true)], null);
        }
        if (($node->raw()['is_deleted'] ?? false) === true) {
            return null;
        }
        $stages = array_values(array_filter($node->list('stages'), static fn(Node $stage): bool => ($stage->raw()['is_deleted'] ?? false) !== true));
        usort($stages, static fn(Node $a, Node $b): int => $a->int('order_nr') <=> $b->int('order_nr'));

        return BoardContract::board(
            $node->id(),
            $node->string('name'),
            null,
            array_map(static function (Node $stage): array {
                $probability = $stage->optionalInt('deal_probability');

                return BoardContract::stage((string) $stage->int('id'), $stage->string('name'), false, null, $probability === null ? null : Decimal::of($probability));
            }, $stages),
            Fields::time($node, 'add_time'),
            ['deal_probability_enabled' => $node->raw()['is_deal_probability_enabled'] ?? null],
        );
    }
}
