<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Proves the plugin's GraphQL against Shopify's real Admin schema (cached by tools/fetch-schema.php):
 *  1. every operation in graphql/operations parses and validates;
 *  2. the static cost estimate (tools/QueryCost.php) matches every cost recorded from a live store
 *     (tests/fixtures/shopify-query-cost.json), and every operation fits under Shopify's single-query limit,
 *     with PageSizes' margin, once its page sizes are shrunk to 1: an operation that can't is unfixable at
 *     run time. Operations over the limit at their default sizes are listed (the client shrinks them);
 *  3. every recorded response in tests/fixtures/shopify executes back through its operation unchanged,
 *     so each fixture has exactly the shape (field names, enums, non-nulls) Shopify returns for it.
 * Exits non-zero on the first class of failure, listing every failure in it.
 */

use GraphQL\Executor\Executor;
use GraphQL\GraphQL;
use GraphQL\Language\Parser;
use GraphQL\Validator\DocumentValidator;
use IdempotentImporter\Source\Shopify\ApiVersion;
use IdempotentImporter\Source\Shopify\PageSizes;
use IdempotentImporter\Source\Shopify\QueryLibrary;
use IdempotentImporter\Tests\Support\Fixtures;
use IdempotentImporter\Tools\CostCalibration;
use IdempotentImporter\Tools\QueryCost;
use IdempotentImporter\Tools\SchemaCache;

require __DIR__ . '/../vendor/autoload.php';

const SMALLEST_PAGE = 1;

$schema = SchemaCache::load(ApiVersion::PINNED);
$library = QueryLibrary::bundled();
$failures = [];

foreach ($library->operations() as $operation) {
    try {
        $errors = DocumentValidator::validate($schema, Parser::parse($library->document($operation)));
    } catch (Throwable $e) {
        $failures[] = "{$operation}: " . $e->getMessage();
        continue;
    }
    foreach ($errors as $error) {
        $failures[] = "{$operation}: " . $error->getMessage();
    }
}
if ($failures !== []) {
    fwrite(STDERR, "Invalid operations:\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}

$cost = new QueryCost($schema);
$limit = PageSizes::MAX_QUERY_COST * PageSizes::TARGET_SHARE;
$recorded = CostCalibration::operations();
$shrinking = [];
foreach ($library->operations() as $operation) {
    $document = $library->document($operation);
    if (str_starts_with(CostCalibration::stripComments($document), 'mutation')) {
        continue;
    }
    if (!isset($recorded[$operation])) {
        $failures[] = "{$operation}: no cost recorded from a live store; run tools/calibrate-query-cost.php";
        continue;
    }
    $estimate = $cost->of($document, $recorded[$operation]['variables']);
    if ($estimate !== $recorded[$operation]['cost']) {
        $failures[] = "{$operation}: estimated cost {$estimate}, but Shopify charged {$recorded[$operation]['cost']}";
    }
    $smallest = $cost->of($document, array_map(static fn(): int => SMALLEST_PAGE, $library->pageSizes($operation)));
    if ($smallest > $limit) {
        $failures[] = "{$operation}: costs {$smallest} even with every page size at 1, over {$limit}";
    }
    if ($estimate > PageSizes::MAX_QUERY_COST) {
        $shrinking[] = "{$operation} ({$estimate})";
    }
}
if ($failures !== []) {
    fwrite(STDERR, "Query costs:\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}

Executor::setDefaultFieldResolver(Fixtures::fieldResolver(...));
$checked = 0;
foreach (Fixtures::all() as $name => $fixture) {
    $result = GraphQL::executeQuery(
        $schema,
        $library->document($fixture->operation),
        $fixture->data,
        null,
        [...$library->pageSizes($fixture->operation), ...$fixture->variables],
    )->toArray();
    if (isset($result['errors'])) {
        $failures[] = "{$name}: " . json_encode($result['errors'], JSON_UNESCAPED_SLASHES);
        continue;
    }
    $difference = Fixtures::firstDifference($fixture->data, $result['data'] ?? []);
    if ($difference !== null) {
        $failures[] = "{$name}: {$difference}";
    }
    ++$checked;
}
if ($failures !== []) {
    fwrite(STDERR, "Fixtures that don't match Shopify's response shape:\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, count($library->operations()) . ' operations valid; ' . count($recorded) . " costs match the live calibration; {$checked} fixtures match the schema.\n");
if ($shrinking !== []) {
    fwrite(STDOUT, 'Over the single-query limit at default page sizes (shrunk at run time): ' . implode(', ', $shrinking) . "\n");
}
