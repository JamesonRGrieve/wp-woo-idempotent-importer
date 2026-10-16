<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Every source's published API documents (the catalog the REST fixtures are validated against), cached
 * locally by `composer specs`. URLs are pinned: a versioned document or a repository commit.
 */
final class SpecCache
{
    public const FETCH_TIMEOUT_SECONDS = 120;

    /** @return list<SpecDocument> */
    public static function catalog(): array
    {
        return [
            new SpecDocument('etsy', 'open-api-v3', 'https://www.etsy.com/openapi/generated/oas/3.0.0.json', '', true),
            // Square API 2026-09-16 (square/connect-api-specification, "Release 2026-09-16").
            new SpecDocument(
                'square_online',
                'connect-api-2026-09-16',
                'https://raw.githubusercontent.com/square/connect-api-specification/79ccc3f89e1527f7b943cc2f6628b6098572583e/api.json',
                '',
                false,
            ),
        ];
    }

    /** @return list<SpecDocument> the documents of one source */
    public static function forSource(string $source): array
    {
        return array_values(array_filter(self::catalog(), static fn(SpecDocument $document): bool => $document->source === $source));
    }

    public static function write(SpecDocument $document, string $body): void
    {
        self::decode($document, $body);
        $path = $document->cachePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            throw new RuntimeException("Could not create {$dir}");
        }
        if (file_put_contents($path, $body) === false) {
            throw new RuntimeException("Could not write {$path}");
        }
    }

    public static function load(SpecDocument $document): ApiSpec
    {
        $path = $document->cachePath();
        if (!is_file($path)) {
            throw new RuntimeException("No cached spec at {$path}; run `composer specs` first.");
        }

        return ApiSpec::decode(self::decode($document, (string) file_get_contents($path)), $document->prefixPattern);
    }

    private static function decode(SpecDocument $document, string $body): mixed
    {
        return $document->format === SpecDocument::FORMAT_YAML
            ? Yaml::parse($body)
            : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
