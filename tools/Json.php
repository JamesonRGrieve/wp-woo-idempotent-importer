<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

use RuntimeException;

/** Checked narrowing of decoded JSON for the tools. */
final class Json
{
    /** @return array<string, mixed> */
    public static function object(mixed $value, string $what): array
    {
        if (!is_array($value)) {
            throw new RuntimeException("{$what} is not an object");
        }
        $object = [];
        foreach ($value as $key => $item) {
            $object[is_string($key) ? $key : throw new RuntimeException("{$what} has a non-string key")] = $item;
        }

        return $object;
    }
}
