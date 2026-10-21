<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Store;

use wpdb;

/**
 * Mirrored gift cards, store credit and loyalty accounts, and their ledgers. Square holds the one spendable balance; this keeps
 * what Square can't: each card's source history (Square can't backdate activity) beside every write the
 * importer made in Square, and the source balance last brought into Square, so only what the source
 * changed is ever applied. Empty strings stand for "none".
 */
final class GiftCardStore
{
    /** A loyalty account (points) kept beside the cards: same ledger, its balances in points. */
    public const LOYALTY = 'loyalty';
    public const RETIRED = 'retired';
    /** A ledger entry from the card's source history. */
    public const SOURCE_ENTRY = 'source';
    /** A ledger entry for an activity the importer wrote in Square. */
    public const SQUARE_ENTRY = 'square';

    public function __construct(private readonly wpdb $db, private readonly Tables $tables) {}

    public function find(string $gid): ?GiftCardRecord
    {
        $row = Sql::row($this->db, 'SELECT * FROM %i WHERE gid = %s', $this->cards(), $gid);

        return $row === null ? null : GiftCardRecord::fromRow($row);
    }

    public function byId(int $id): ?GiftCardRecord
    {
        $row = Sql::row($this->db, 'SELECT * FROM %i WHERE id = %d', $this->cards(), $id);

        return $row === null ? null : GiftCardRecord::fromRow($row);
    }

    /** @return list<GiftCardRecord> a customer's cards and accounts in Square, newest first */
    public function forUser(int $userId): array
    {
        return array_map(GiftCardRecord::fromRow(...), Sql::rows($this->db, "SELECT * FROM %i WHERE user_id = %d AND square_id <> '' ORDER BY id DESC", $this->cards(), $userId));
    }

    /** Creates or updates the card (keyed by its source identity); returns its id. */
    public function save(GiftCardRecord $card): int
    {
        Sql::execute(
            $this->db,
            'INSERT INTO %i (gid, kind, gan, square_id, currency, source_balance, loaded_balance, state, user_id, code_hint, held, details, updated_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE kind = VALUES(kind), gan = VALUES(gan), square_id = VALUES(square_id), currency = VALUES(currency),
               source_balance = VALUES(source_balance), loaded_balance = VALUES(loaded_balance), state = VALUES(state),
               user_id = VALUES(user_id), code_hint = VALUES(code_hint), held = VALUES(held), details = VALUES(details), updated_at = VALUES(updated_at)',
            $this->cards(),
            $card->gid,
            $card->kind,
            $card->gan,
            $card->squareId,
            $card->currency,
            $card->sourceBalance,
            $card->loadedBalance,
            $card->state,
            $card->userId,
            $card->codeHint,
            $card->held,
            $card->details,
            gmdate('Y-m-d H:i:s'),
        );

        return $this->find($card->gid)->id ?? throw new \RuntimeException("Gift card {$card->gid} was not saved.");
    }

    /** Marks a card or account gone at its source; it stays (and stays in Square) as it was. */
    public function retire(GiftCardRecord $card): void
    {
        $this->save(new GiftCardRecord($card->id, $card->gid, $card->kind, $card->gan, $card->squareId, $card->currency, $card->sourceBalance, $card->loadedBalance, self::RETIRED, $card->userId, $card->codeHint, $card->held, $card->details));
    }

    /**
     * Replaces the card's source history (the importer's own Square entries stay).
     *
     * @param list<LedgerEntry> $entries
     */
    public function replaceSourceHistory(int $cardId, array $entries): void
    {
        Sql::execute($this->db, 'DELETE FROM %i WHERE card_id = %d AND origin = %s', $this->ledgerTable(), $cardId, self::SOURCE_ENTRY);
        foreach ($entries as $entry) {
            $this->addEntry($cardId, self::SOURCE_ENTRY, $entry);
        }
    }

    /** Records an activity the importer wrote in Square (once per entry id). */
    public function addSquareEntry(int $cardId, LedgerEntry $entry): void
    {
        $this->addEntry($cardId, self::SQUARE_ENTRY, $entry);
    }

    /** @return list<LedgerEntry> the card's ledger: its source history in the source's order, then what the importer wrote in Square */
    public function ledger(int $cardId): array
    {
        return array_map(
            LedgerEntry::fromRow(...),
            Sql::rows($this->db, 'SELECT * FROM %i WHERE card_id = %d ORDER BY origin = %s, id', $this->ledgerTable(), $cardId, self::SQUARE_ENTRY),
        );
    }

    private function addEntry(int $cardId, string $origin, LedgerEntry $entry): void
    {
        Sql::execute(
            $this->db,
            'INSERT IGNORE INTO %i (card_id, entry_gid, origin, occurred_at, kind, amount, balance_after, reference, note)
             VALUES (%d, %s, %s, NULLIF(%s, \'\'), %s, %s, %s, %s, %s)',
            $this->ledgerTable(),
            $cardId,
            $entry->gid,
            $origin,
            $entry->occurredAt === null ? '' : str_replace(['T', 'Z'], [' ', ''], $entry->occurredAt),
            $entry->kind,
            $entry->amount,
            $entry->balanceAfter ?? '',
            $entry->reference ?? '',
            $entry->note ?? '',
        );
    }

    private function cards(): string
    {
        return $this->tables->name(Tables::GIFT_CARDS);
    }

    private function ledgerTable(): string
    {
        return $this->tables->name(Tables::GIFT_CARD_LEDGER);
    }
}
