<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Proves the plugin's GraphQL against Shopify's real Admin schema (cached by tools/fetch-schema.php):
 *  1. every operation in graphql/operations parses and validates;
 *  2. every recorded response in tests/fixtures/shopify executes back through its operation unchanged,
 *     so each fixture has exactly the shape (field names, enums, non-nulls) Shopify returns for it.
 * Exits non-zero on the first class of failure, listing every failure in it.
 */

use GraphQL\Executor\Executor;
use GraphQL\GraphQL;
use GraphQL\Language\Parser;
use GraphQL\Validator\DocumentValidator;
use IdempotentImporter\Source\Shopify\ApiVersion;
use IdempotentImporter\Source\Shopify\QueryLibrary;
use IdempotentImporter\Tests\Support\Fixtures;
use IdempotentImporter\Tools\SchemaCache;

require __DIR__ . '/../vendor/autoload.php';

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

Executor::setDefaultFieldResolver(Fixtures::fieldResolver(...));
$checked = 0;
foreach (Fixtures::all() as $name => $fixture) {
    $result = GraphQL::executeQuery(
        $schema,
        $library->document($fixture->operation),
        $fixture->data,
        null,
        $fixture->variables,
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
fwrite(STDOUT, count($library->operations()) . " operations valid; {$checked} fixtures match the schema.\n");
