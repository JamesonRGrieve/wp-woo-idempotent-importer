<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Mapping;

/**
 * The change signal: a SHA-256 of the mapping version and the desired state, over canonical JSON (object
 * keys sorted at every depth, list order kept), so equal states always hash equally.
 */
final class Hasher
{
    public static function hash(Desired $desired, int $mappingVersion): string
    {
        return hash('sha256', json_encode(
            self::canonical([$mappingVersion, $desired->fields, $desired->legacyPaths]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
