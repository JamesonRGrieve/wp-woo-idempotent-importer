<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Sync;

use DateTimeImmutable;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Wp\Options;

/**
 * Per kind, the start time of the last run that completed it with nothing failed or deferred. The next
 * incremental run asks for objects updated since then, minus an overlap for clock skew and objects
 * updated while that run was reading.
 */
final class Cursors
{
    public const OPTION = 'isi_cursors';
    public const OVERLAP = 'PT10M';

    public function since(Kind $kind): ?DateTimeImmutable
    {
        $stored = $this->all()[$kind->value] ?? null;

        return $stored === null ? null : (new DateTimeImmutable($stored))->sub(new \DateInterval(self::OVERLAP));
    }

    public function advance(Kind $kind, string $runStartedAt): void
    {
        $all = $this->all();
        $all[$kind->value] = $runStartedAt;
        update_option(self::OPTION, $all, false);
    }

    public function reset(): void
    {
        delete_option(self::OPTION);
    }

    /** @return array<string, string> */
    private function all(): array
    {
        $all = [];
        foreach (Options::array(self::OPTION) as $kind => $startedAt) {
            if (is_string($kind) && is_string($startedAt)) {
                $all[$kind] = $startedAt;
            }
        }

        return $all;
    }
}
