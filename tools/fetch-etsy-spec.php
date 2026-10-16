<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Downloads Etsy's OpenAPI document for the pinned version into .cache/, for validate-etsy-fixtures.php.
 * Usage: composer etsy-spec
 */

use IdempotentImporter\Tools\EtsySpecCache;

require __DIR__ . '/../vendor/autoload.php';

$context = stream_context_create(['http' => ['timeout' => EtsySpecCache::FETCH_TIMEOUT_SECONDS]]);
$body = file_get_contents(EtsySpecCache::URL, false, $context);
if ($body === false) {
    fwrite(STDERR, 'Could not download the Etsy spec from ' . EtsySpecCache::URL . ".\n");
    exit(1);
}
$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
$version = is_array($decoded) && is_array($decoded['info'] ?? null) ? ($decoded['info']['version'] ?? null) : null;
if ($version !== EtsySpecCache::VERSION) {
    fwrite(STDERR, 'Expected Etsy spec ' . EtsySpecCache::VERSION . ', got ' . var_export($version, true) . ".\n");
    exit(1);
}
EtsySpecCache::write($body);
fwrite(STDOUT, 'Cached Etsy Open API ' . EtsySpecCache::VERSION . ' at ' . EtsySpecCache::path() . "\n");
