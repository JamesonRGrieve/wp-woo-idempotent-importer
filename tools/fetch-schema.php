<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Downloads Shopify's public Admin GraphQL schema for the API version the plugin pins, as SDL, into
 * .cache/. The schema is not committed: it is Shopify's, and the cache is rebuilt on demand.
 */

use GraphQL\Type\Introspection;
use GraphQL\Utils\BuildClientSchema;
use GraphQL\Utils\SchemaPrinter;
use IdempotentImporter\Shopify\ApiVersion;
use IdempotentImporter\Tools\SchemaCache;

require __DIR__ . '/../vendor/autoload.php';

$version = ApiVersion::PINNED;
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode(['query' => Introspection::getIntrospectionQuery()], JSON_THROW_ON_ERROR),
        'timeout' => SchemaCache::FETCH_TIMEOUT_SECONDS,
    ],
]);
$body = file_get_contents(SchemaCache::PROXY_URL . $version, false, $context);
if ($body === false) {
    fwrite(STDERR, "Could not download the Shopify schema for {$version}.\n");
    exit(1);
}
$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
    fwrite(STDERR, "The schema proxy returned no data for {$version}.\n");
    exit(1);
}
/** @var array{__schema: array<string, mixed>} $data */
$data = $decoded['data'];
$sdl = SchemaPrinter::doPrint(BuildClientSchema::build($data));
SchemaCache::write($version, $sdl);
fwrite(STDOUT, 'Wrote ' . SchemaCache::path($version) . "\n");
