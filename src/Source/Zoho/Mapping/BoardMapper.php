<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho\Mapping;

use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Zoho\ZohoReader;

/**
 * A deal pipeline → a FluentBoards board with its stages in their sequence (keyed by the stage's actual value, which
 * a deal's Stage holds): a stage whose forecast type is Closed Won or Closed Lost closes its deals, won or lost; its
 * forecast category is kept with the board. The task board → one whose stages are the options of the tasks'
 * status; Completed closes its tasks.
 */
final class BoardMapper implements Mapper
{
    private const VERSION = 1;
    public const COMPLETED = 'Completed';
    private const TASK_BOARD_TITLE = 'Zoho CRM tasks';

    public function kind(): Kind
    {
        return Kind::CrmPipeline;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        if (str_contains($node->id(), '/TaskBoard/')) {
            $options = $node->requiredChild('status_field')->list('pick_list_values');
            usort($options, static fn(Node $a, Node $b): int => $a->int('sequence_number') <=> $b->int('sequence_number'));

            return BoardContract::board($node->id(), self::TASK_BOARD_TITLE, null, array_map(
                static fn(Node $option): array => BoardContract::stage($option->string('actual_value'), $option->string('display_value'), $option->string('actual_value') === self::COMPLETED),
                $options,
            ), null);
        }
        $maps = $node->list('maps');
        usort($maps, static fn(Node $a, Node $b): int => $a->int('sequence_number') <=> $b->int('sequence_number'));
        $stages = [];
        $forecast = [];
        foreach ($maps as $stage) {
            $outcome = ZohoReader::OUTCOMES[$stage->string('forecast_type')] ?? null;
            $stages[] = BoardContract::stage($stage->string('actual_value'), $stage->string('display_value'), $outcome !== null, $outcome);
            $forecast[$stage->string('actual_value')] = $stage->requiredChild('forecast_category')->string('name');
        }

        return BoardContract::board($node->id(), $node->string('display_value'), null, $stages, null, ['forecast_categories' => $forecast, 'default' => ($node->raw()['default'] ?? false) === true]);
    }
}
