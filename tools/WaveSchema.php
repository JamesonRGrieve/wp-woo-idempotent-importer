<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use RuntimeException;

/**
 * Wave's public GraphQL schema (https://gql.waveapps.com/graphql/public answers introspection without a token),
 * cached as SDL by tools/fetch-wave-schema.php. Wave publishes no schema versions, so the SDL is pinned by its
 * SHA-256, as introspected on 2026-10-21: a changed schema fails the fetch until it is reviewed and re-pinned.
 * Not committed: it is Wave's.
 */
final class WaveSchema
{
    public const ENDPOINT = 'https://gql.waveapps.com/graphql/public';
    public const PINNED_SHA256 = 'fe236bbf075e04fe2dcfbd37ae317d91f4a18b11b0f2dcdc17d2302e238d8fe9';
    public const FETCH_TIMEOUT_SECONDS = 120;

    public static function path(): string
    {
        return dirname(__DIR__) . '/.cache/wave-public.graphql';
    }

    public static function load(): Schema
    {
        $path = self::path();
        $sdl = is_file($path) ? file_get_contents($path) : false;
        if ($sdl === false) {
            throw new RuntimeException("No cached Wave schema at {$path}; run `composer schema` first.");
        }

        return BuildSchema::build($sdl);
    }
}
