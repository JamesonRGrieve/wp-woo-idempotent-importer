<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

/** URL slugs for objects whose source gives none (WordPress keeps slugs unique itself). */
final class Slugs
{
    /** "Wool Toque (Navy)" → "wool-toque-navy". */
    public static function fromTitle(string $title): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    }
}
