<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\HubSpot\Mapping;

use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A deal pipeline → a FluentBoards board with its stages in HubSpot's display order (archived stages left out, so
 * the board archives them). A stage HubSpot marks closed (`metadata.isClosed`) closes its deals: won at a
 * probability of 1, lost at 0. Each stage's probability is kept with the board's stage definitions. An archived
 * pipeline retires its board.
 *
 * The task board is the account's task list: a stage per option of the task status property, in its order;
 * `COMPLETED` closes its tasks.
 */
final class PipelineMapper implements Mapper
{
    private const VERSION = 1;
    public const TASK_BOARD_TITLE = 'HubSpot tasks';
    public const COMPLETED = 'COMPLETED';

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
        if (str_contains($node->id(), '/TaskBoard/')) {
            return $this->taskBoard($node);
        }
        if (($node->raw()['archived'] ?? false) === true) {
            return null;
        }
        $stages = array_values(array_filter($node->list('stages'), static fn(Node $stage): bool => ($stage->raw()['archived'] ?? false) !== true));
        usort($stages, static fn(Node $a, Node $b): int => [$a->int('displayOrder'), $a->string('label')] <=> [$b->int('displayOrder'), $b->string('label')]);

        return BoardContract::board($node->id(), $node->string('label'), null, array_map(self::stage(...), $stages), Times::iso($node, 'createdAt'));
    }

    /** @return array{key: string, title: string, closed: bool, outcome: ?string, probability: ?string} */
    private static function stage(Node $stage): array
    {
        $metadata = $stage->child('metadata');
        $probability = $metadata?->optionalString('probability');
        $probability = $probability === null ? null : Decimal::of($probability);
        $closed = $metadata?->optionalString('isClosed') === 'true';
        $outcome = match (true) {
            !$closed => null,
            $probability === '1' => BoardContract::WON,
            $probability === '0' => BoardContract::LOST,
            default => null,
        };

        return BoardContract::stage($stage->string('id'), $stage->string('label'), $closed, $outcome, $probability);
    }

    private function taskBoard(Node $node): Desired
    {
        $options = $node->requiredChild('status_property')->list('options');
        usort($options, static fn(Node $a, Node $b): int => $a->int('displayOrder') <=> $b->int('displayOrder'));
        $stages = array_map(
            static fn(Node $option): array => BoardContract::stage($option->string('value'), $option->string('label'), $option->string('value') === self::COMPLETED),
            $options,
        );

        return BoardContract::board($node->id(), self::TASK_BOARD_TITLE, null, $stages, null);
    }
}
