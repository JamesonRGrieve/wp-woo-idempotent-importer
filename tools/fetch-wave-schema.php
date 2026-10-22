<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Downloads Wave's public GraphQL schema by introspection, as SDL, into .cache/, and checks it is the schema the
 * adapter is pinned to (tools/WaveSchema.php). A changed schema is written but fails, so it is reviewed first.
 */

use GraphQL\Type\Introspection;
use GraphQL\Utils\BuildClientSchema;
use GraphQL\Utils\SchemaPrinter;
use IdempotentImporter\Tools\WaveSchema;

require __DIR__ . '/../vendor/autoload.php';

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode(['query' => Introspection::getIntrospectionQuery()], JSON_THROW_ON_ERROR),
        'timeout' => WaveSchema::FETCH_TIMEOUT_SECONDS,
    ],
]);
$body = file_get_contents(WaveSchema::ENDPOINT, false, $context);
if ($body === false) {
    fwrite(STDERR, "Could not download Wave's schema.\n");
    exit(1);
}
$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
    fwrite(STDERR, "Wave's introspection returned no data.\n");
    exit(1);
}
/** @var array{__schema: array<string, mixed>} $data */
$data = $decoded['data'];
$sdl = SchemaPrinter::doPrint(BuildClientSchema::build($data));
$path = WaveSchema::path();
if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o755, true)) {
    fwrite(STDERR, 'Could not create ' . dirname($path) . "\n");
    exit(1);
}
file_put_contents($path, $sdl);
$hash = hash('sha256', $sdl);
if ($hash !== WaveSchema::PINNED_SHA256) {
    fwrite(STDERR, "Wave's schema changed (SHA-256 {$hash}, pinned " . WaveSchema::PINNED_SHA256 . "): review it, then re-pin.\n");
    exit(1);
}
fwrite(STDOUT, "Wrote {$path}\n");
