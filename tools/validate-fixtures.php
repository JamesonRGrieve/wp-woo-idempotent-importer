<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Validates every recorded REST response (tests/fixtures/<source>/<state>) against that source's pinned,
 * published API documents: the request must be an operation one of them defines, and the response must
 * match its 200 schema strictly (see OpenApiValidator). Usage: composer validate-fixtures
 */

use IdempotentImporter\Tests\Support\SourceFixtures;
use IdempotentImporter\Tools\OpenApiValidator;
use IdempotentImporter\Tools\SpecCache;

require __DIR__ . '/../vendor/autoload.php';

$failures = [];
$counts = [];
foreach (array_unique(array_map(static fn($document): string => $document->source, SpecCache::catalog())) as $source) {
    $specs = [];
    foreach (SpecCache::forSource($source) as $document) {
        $specs[] = [SpecCache::load($document), $document->completeObjects];
    }
    foreach (SourceFixtures::states($source) as $state) {
        foreach (SourceFixtures::state($source, $state) as $fixture) {
            $counts[$source] = ($counts[$source] ?? 0) + 1;
            $name = "{$source}/{$state}/" . basename($fixture['file']);
            $matched = false;
            foreach ($specs as [$spec, $completeObjects]) {
                $schema = $spec->responseSchema($fixture['method'], $fixture['path']);
                if ($schema === null) {
                    continue;
                }
                $matched = true;
                $validator = new OpenApiValidator($spec->schemas, $spec->refPrefix, $completeObjects);
                foreach ($validator->validate($fixture['response'], $schema) as $error) {
                    $failures[] = "{$name}: {$error}";
                }
                break;
            }
            if (!$matched) {
                $failures[] = "{$name}: {$fixture['method']} {$fixture['path']} is not an operation in the {$source} spec";
            }
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
foreach ($counts as $source => $count) {
    fwrite(STDOUT, "{$count} {$source} fixtures match the published {$source} API spec.\n");
}
