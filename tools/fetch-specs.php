<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Downloads every source's pinned API documents (SpecCache::catalog) into .cache/specs/, for
 * validate-fixtures.php. A document pinned by hash that has changed upstream fails the fetch. A document
 * committed to the repository (a source with no published spec) is only checked to be readable.
 * Usage: composer specs [-- <source>]
 */

use IdempotentImporter\Tools\SpecCache;

require __DIR__ . '/../vendor/autoload.php';

$only = $argv[1] ?? null;
$context = stream_context_create(['http' => ['timeout' => SpecCache::FETCH_TIMEOUT_SECONDS, 'user_agent' => 'wp-woo-idempotent-importer spec fetch']]);
foreach (SpecCache::catalog() as $document) {
    if ($only !== null && $document->source !== $only) {
        continue;
    }
    if ($document->isCommitted()) {
        try {
            SpecCache::load($document);
        } catch (RuntimeException|JsonException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        fwrite(STDOUT, "Committed {$document->source}/{$document->name} at {$document->cachePath()} (nothing to fetch)\n");

        continue;
    }
    $body = file_get_contents($document->url, false, $context);
    if ($body === false) {
        fwrite(STDERR, "Could not download {$document->source}/{$document->name} from {$document->url}.\n");
        exit(1);
    }
    try {
        SpecCache::write($document, $body);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    fwrite(STDOUT, "Cached {$document->source}/{$document->name} at {$document->cachePath()}\n");
}
