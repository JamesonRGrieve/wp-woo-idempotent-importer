<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Validates every recorded Etsy response (tests/fixtures/etsy) against the pinned Etsy OpenAPI spec:
 * the request path must be a GET operation the spec defines, and the response must match that operation's
 * 200 schema strictly (see OpenApiValidator). Usage: composer validate-etsy
 */

use IdempotentImporter\Tests\Support\EtsyFixtures;
use IdempotentImporter\Tools\EtsySpecCache;
use IdempotentImporter\Tools\OpenApiValidator;

require __DIR__ . '/../vendor/autoload.php';

$spec = EtsySpecCache::load();
$validator = new OpenApiValidator($spec->schemas);

$failures = [];
$count = 0;
foreach (EtsyFixtures::states() as $state) {
    foreach (EtsyFixtures::state($state) as $fixture) {
        ++$count;
        $name = "{$state}/" . basename($fixture['file']);
        $schema = $spec->responseSchema($fixture['path']);
        if ($schema === null) {
            $failures[] = "{$name}: GET {$fixture['path']} is not an operation in the Etsy spec";
            continue;
        }
        foreach ($validator->validate($fixture['response'], $schema) as $error) {
            $failures[] = "{$name}: {$error}";
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "{$count} Etsy fixtures match the Etsy Open API " . EtsySpecCache::VERSION . " spec.\n");
