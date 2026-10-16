<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

use RuntimeException;

/** Etsy's published OpenAPI document (Open API v3), pinned by version and cached locally. */
final class EtsySpecCache
{
    public const VERSION = '3.0.0';
    public const URL = 'https://www.etsy.com/openapi/generated/oas/' . self::VERSION . '.json';
    public const FETCH_TIMEOUT_SECONDS = 120;

    public static function path(): string
    {
        return dirname(__DIR__) . '/.cache/etsy-oas-' . self::VERSION . '.json';
    }

    public static function write(string $json): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            throw new RuntimeException("Could not create {$dir}");
        }
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("Could not write {$path}");
        }
    }

    public static function load(): EtsySpec
    {
        $path = self::path();
        if (!is_file($path)) {
            throw new RuntimeException("No cached Etsy spec at {$path}; run `composer etsy-spec` first.");
        }

        return EtsySpec::decode(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }
}
