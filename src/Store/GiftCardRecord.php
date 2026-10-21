<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Store;

/**
 * One mirrored gift card (or store credit account) and where it stands in Square. `loadedBalance` is the
 * source balance last brought into Square; `held` says why a change isn't in Square ('' when none);
 * `details` keeps the source's other fields (JSON), so nothing mapped is dropped.
 */
final readonly class GiftCardRecord
{
    public const GIFT_CARD = 'gift_card';
    public const STORE_CREDIT = 'store_credit';

    public function __construct(
        public int $id,
        public string $gid,
        public string $kind,
        public string $gan,
        public string $squareId,
        public string $currency,
        public string $sourceBalance,
        public string $loadedBalance,
        public string $state,
        public int $userId,
        public string $codeHint,
        public string $held,
        public string $details = '',
    ) {}

    /** @param array<string, string|null> $row */
    public static function fromRow(array $row): self
    {
        $text = static fn(string $column): string => (string) ($row[$column] ?? '');

        return new self(
            (int) $text('id'),
            $text('gid'),
            $text('kind'),
            $text('gan'),
            $text('square_id'),
            $text('currency'),
            $text('source_balance'),
            $text('loaded_balance'),
            $text('state'),
            (int) $text('user_id'),
            $text('code_hint'),
            $text('held'),
            $text('details'),
        );
    }

    public function with(string $squareId, string $loadedBalance, string $held): self
    {
        return new self($this->id, $this->gid, $this->kind, $this->gan, $squareId, $this->currency, $this->sourceBalance, $loadedBalance, $this->state, $this->userId, $this->codeHint, $held, $this->details);
    }
}
