<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce\Mapping;

use IdempotentImporter\Mapping\BoardContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * The opportunity board → a FluentBoards board whose stages are the org's active opportunity stages in their sort
 * order (keyed by API name, the value an opportunity's StageName holds): a closed stage closes its opportunities,
 * won or lost; its default probability (a percentage) and forecast category are kept with the stage definitions,
 * in the org's default currency. The task board → one whose stages are the org's task statuses; a closed status
 * closes its tasks.
 */
final class BoardMapper implements Mapper
{
    private const VERSION = 1;
    public const OPPORTUNITIES = 'Opportunity';
    public const TASKS = 'Task';
    private const TITLES = [self::OPPORTUNITIES => 'Salesforce opportunities', self::TASKS => 'Salesforce tasks'];

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
        $board = Fields::sourceId($node);
        $stages = [];
        $forecast = [];
        foreach ($node->list('stages') as $stage) {
            if ($board === self::OPPORTUNITIES && !Fields::flag($stage, 'IsActive')) {
                continue;
            }
            $closed = Fields::flag($stage, 'IsClosed');
            $key = $stage->string('ApiName');
            $stages[] = BoardContract::stage(
                $key,
                $stage->string('MasterLabel'),
                $closed,
                $board === self::OPPORTUNITIES && $closed ? (Fields::flag($stage, 'IsWon') ? BoardContract::WON : BoardContract::LOST) : null,
                Fields::text($stage, 'DefaultProbability'),
            );
            $category = Fields::text($stage, 'ForecastCategoryName');
            if ($category !== null) {
                $forecast[$key] = $category;
            }
        }

        return BoardContract::board($node->id(), self::TITLES[$board] ?? $board, $node->optionalString('org_currency'), $stages, null, ['forecast_categories' => $forecast]);
    }
}
