<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use RuntimeException;

/** The locally cached copy of Shopify's Admin GraphQL schema (see tools/fetch-schema.php). */
final class SchemaCache
{
    public const PROXY_URL = 'https://shopify.dev/admin-graphql-direct-proxy/';
    public const FETCH_TIMEOUT_SECONDS = 120;

    public static function path(string $version): string
    {
        return dirname(__DIR__) . "/.cache/shopify-admin-{$version}.graphql";
    }

    public static function write(string $version, string $sdl): void
    {
        $path = self::path($version);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            throw new RuntimeException("Could not create {$dir}");
        }
        if (file_put_contents($path, $sdl) === false) {
            throw new RuntimeException("Could not write {$path}");
        }
    }

    public static function load(string $version): Schema
    {
        $path = self::path($version);
        if (!is_file($path)) {
            throw new RuntimeException("No cached schema at {$path}; run `composer schema` first.");
        }
        $sdl = file_get_contents($path);
        if ($sdl === false) {
            throw new RuntimeException("Could not read {$path}");
        }

        return BuildSchema::build($sdl);
    }
}
