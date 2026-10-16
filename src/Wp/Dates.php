<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use DateTimeImmutable;
use DateTimeZone;

/** Shopify ISO-8601 timestamps → the GMT and site-local "Y-m-d H:i:s" strings WordPress stores. */
final class Dates
{
    private const MYSQL_FORMAT = 'Y-m-d H:i:s';

    public static function gmt(string $iso): string
    {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format(self::MYSQL_FORMAT);
    }

    public static function local(string $iso): string
    {
        return (new DateTimeImmutable($iso))->setTimezone(wp_timezone())->format(self::MYSQL_FORMAT);
    }
}
