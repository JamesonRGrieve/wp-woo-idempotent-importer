<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Fluent;

/**
 * Which of a Fluent model's attributes differ from what the mirror wants (FluentCRM and FluentBoards share one
 * ORM, each in its own namespace), compared as stored text, so an unchanged model is never re-saved.
 */
final class Attributes
{
    /**
     * @param callable(string): mixed $current the model's attribute
     * @param array<string, scalar|null> $desired
     *
     * @return array<string, scalar|null> those that differ
     */
    public static function changes(callable $current, array $desired): array
    {
        $changes = [];
        foreach ($desired as $name => $value) {
            $stored = $current($name);
            if ((is_scalar($stored) ? (string) $stored : null) !== ($value === null ? null : (string) $value)) {
                $changes[$name] = $value;
            }
        }

        return $changes;
    }
}
