<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Proves the Wave adapter's GraphQL against Wave's public schema (pinned and cached by tools/fetch-wave-schema.php):
 *  1. every operation (src/Source/Wave/Operations.php) parses and validates;
 *  2. every recorded answer in tests/fixtures/wave executes back through the operation it answers, with its
 *     variables, unchanged: each fixture has exactly the shape (field names, enums, non-nulls) Wave's schema gives.
 * Exits non-zero listing every failure.
 */

use GraphQL\Executor\Executor;
use GraphQL\GraphQL;
use GraphQL\Language\Parser;
use GraphQL\Validator\DocumentValidator;
use IdempotentImporter\Source\Wave\Operations;
use IdempotentImporter\Tests\Support\Fixtures;
use IdempotentImporter\Tests\Support\SourceFixtures;
use IdempotentImporter\Tools\WaveSchema;

require __DIR__ . '/../vendor/autoload.php';

const SOURCE = 'wave';

$schema = WaveSchema::load();
$failures = [];
foreach (Operations::all() as $name => $document) {
    foreach (DocumentValidator::validate($schema, Parser::parse($document)) as $error) {
        $failures[] = "{$name}: " . $error->getMessage();
    }
}
if ($failures !== []) {
    fwrite(STDERR, "Invalid Wave operations:\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}

Executor::setDefaultFieldResolver(Fixtures::fieldResolver(...));
$checked = 0;
foreach (SourceFixtures::states(SOURCE) as $state) {
    foreach (SourceFixtures::state(SOURCE, $state) as $fixture) {
        $file = SOURCE . "/{$state}/" . basename($fixture['file']);
        $body = $fixture['body'];
        $operation = is_string($body['operationName'] ?? null) ? $body['operationName'] : '';
        $data = $fixture['response']['data'] ?? null;
        if (!isset(Operations::all()[$operation]) || !is_array($data)) {
            $failures[] = "{$file}: answers no Wave operation (operationName {$operation}) with data";
            continue;
        }
        /** @var array<string, mixed> $variables */
        $variables = is_array($body['variables'] ?? null) ? $body['variables'] : [];
        $result = GraphQL::executeQuery($schema, Operations::document($operation), $data, null, $variables)->toArray();
        if (isset($result['errors'])) {
            $failures[] = "{$file}: " . json_encode($result['errors'], JSON_UNESCAPED_SLASHES);
            continue;
        }
        $difference = Fixtures::firstDifference($data, $result['data'] ?? []);
        if ($difference !== null) {
            $failures[] = "{$file}: {$difference}";
        }
        ++$checked;
    }
}
if ($failures !== []) {
    fwrite(STDERR, "Wave fixtures that don't match Wave's schema:\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, count(Operations::all()) . " Wave operations valid; {$checked} wave fixtures match Wave's schema.\n");
