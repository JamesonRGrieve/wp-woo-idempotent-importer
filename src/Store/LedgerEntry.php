<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Store;

/**
 * One line of a gift card's ledger: something that changed its balance (at the source, or written by the
 * importer in Square). `amount` is signed (a redemption is negative); `occurredAt` is ISO 8601 UTC, or null
 * for source history with no date.
 */
final readonly class LedgerEntry
{
    public function __construct(
        public string $gid,
        public ?string $occurredAt,
        public string $kind,
        public string $amount,
        public ?string $balanceAfter = null,
        public ?string $reference = null,
        public ?string $note = null,
        public string $origin = GiftCardStore::SOURCE_ENTRY,
    ) {}

    /** @param array<string, string|null> $row */
    public static function fromRow(array $row): self
    {
        $optional = static fn(string $column): ?string => ($row[$column] ?? '') === '' ? null : $row[$column];
        $at = $optional('occurred_at');

        return new self(
            (string) ($row['entry_gid'] ?? ''),
            $at === null ? null : str_replace(' ', 'T', $at) . 'Z',
            (string) ($row['kind'] ?? ''),
            (string) ($row['amount'] ?? '0'),
            $optional('balance_after'),
            $optional('reference'),
            $optional('note'),
            (string) ($row['origin'] ?? GiftCardStore::SOURCE_ENTRY),
        );
    }
}
