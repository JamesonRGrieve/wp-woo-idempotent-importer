<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use DateTimeImmutable;
use DateTimeZone;

/** Builds a Shopify search `query:` string from the incremental window and the operator's filter. */
final class SearchQuery
{
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s\Z';

    public static function build(?DateTimeImmutable $updatedSince, string $filter): ?string
    {
        $terms = [];
        if ($updatedSince !== null) {
            $utc = $updatedSince->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP_FORMAT);
            $terms[] = "updated_at:>='{$utc}'";
        }
        $filter = trim($filter);
        if ($filter !== '') {
            $terms[] = "({$filter})";
        }

        return $terms === [] ? null : implode(' AND ', $terms);
    }
}
