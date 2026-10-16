<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;

/**
 * A source's timestamp field (ISO 8601 / RFC 3339 or RFC 2822, with any offset and fraction) as the ISO 8601
 * UTC time every writer takes (`2026-09-01T16:00:00Z`; fractions of a second are dropped).
 */
final class Times
{
    public const ISO_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** @return string|null null when the field is absent or empty */
    public static function iso(Node $node, string $field): ?string
    {
        $value = $node->optionalString($field);
        if ($value === null) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format(self::ISO_FORMAT);
        } catch (Exception) {
            throw new SourceError("{$field} is not a timestamp: {$value}");
        }
    }

    public static function requiredIso(Node $node, string $field): string
    {
        return self::iso($node, $field) ?? throw new SourceError("{$field} is missing.");
    }
}
