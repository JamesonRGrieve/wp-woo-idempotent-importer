<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Model\Kind;

/**
 * The shared field contract of a gift card or store credit account, whatever the source, which
 * GiftCardWriter reads. Money is exact decimal strings in `currency`; times are ISO 8601 UTC. `code` is the
 * full code the customer spends with (null when the source hides it); `state` is active, disabled or
 * expired; `ledger` is the source's own history, oldest first, each entry signed (a redemption is
 * negative). The Square policies are part of the object, so changing one rewrites every card.
 */
final class GiftCardContract
{
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';
    public const EXPIRED = 'expired';
    public const STORE_CREDIT = 'store_credit';
    public const GIFT_CARD = 'gift_card';

    /**
     * @param list<array{gid: string, at: ?string, kind: string, amount: string, balance_after: ?string, reference: ?string, note: ?string}> $ledger
     * @param array<array-key, mixed> $details the source's other fields, kept with the card
     */
    public static function desired(
        string $gid,
        Settings $settings,
        string $kind,
        ?string $code,
        ?string $codeHint,
        string $currency,
        string $balance,
        string $state,
        ?string $customerGid,
        ?string $email,
        ?string $givenName,
        ?string $familyName,
        array $ledger,
        array $details,
    ): Desired {
        return new Desired(Kind::GiftCard, $gid, [
            'kind' => $kind,
            'code' => $code,
            'code_hint' => $codeHint,
            'currency' => strtoupper($currency),
            'balance' => $balance,
            'state' => $state,
            'customer_gid' => $customerGid,
            'email' => $email === null ? null : strtolower($email),
            'given_name' => $givenName,
            'family_name' => $familyName,
            'ledger' => $ledger,
            'details' => $details,
            'load_increases' => $settings->giftCardLoadIncreases,
            'new_numbers' => $settings->giftCardNewNumbers,
        ]);
    }

    /** @return array{gid: string, at: ?string, kind: string, amount: string, balance_after: ?string, reference: ?string, note: ?string} */
    public static function entry(string $gid, ?string $at, string $kind, string $amount, ?string $balanceAfter = null, ?string $reference = null, ?string $note = null): array
    {
        return ['gid' => $gid, 'at' => $at, 'kind' => $kind, 'amount' => $amount, 'balance_after' => $balanceAfter, 'reference' => $reference, 'note' => $note];
    }
}
