<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\LoyaltyContract;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A Wix Loyalty Program account → a Square Loyalty account (LoyaltyContract): its points balance, the
 * contact it belongs to (a mirrored customer, whose phone Square enrols by), and its transactions as the
 * ledger: earned and refunded points add, redeemed and expired points take away, adjustments are as signed
 * as Wix records them. Its tier, expiring points and point totals are kept with the account.
 */
final class LoyaltyAccountMapper implements Mapper
{
    private const VERSION = 1;
    private const SUBTRACTING = ['REDEEM', 'EXPIRE'];
    private const DETAILS = ['memberId', 'tier', 'pointsExpiration', 'rewardAvailable', 'lastActivityDate', 'createdDate'];

    public function kind(): Kind
    {
        return Kind::LoyaltyAccount;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $contact = $node->child('contact');
        $contactId = $node->optionalString('contactId');
        [$given, $family] = array_pad(explode(' ', trim((string) $contact?->optionalString('name')), 2), 2, null);
        $ledger = [];
        foreach ($node->list('transactions') as $transaction) {
            $type = $transaction->string('transactionType');
            $amount = $transaction->optionalInt('amount') ?? 0;
            $ledger[] = LoyaltyContract::entry(
                Fields::id('LoyaltyTransaction', $transaction->string('id')),
                Fields::time($transaction, 'createdDate'),
                strtolower($type),
                in_array($type, self::SUBTRACTING, true) ? -abs($amount) : $amount,
                $transaction->optionalString('description') === '' ? null : $transaction->optionalString('description'),
            );
        }

        return LoyaltyContract::desired(
            $node->id(),
            $node->child('points')?->optionalInt('balance') ?? 0,
            $contactId === null ? null : Fields::contactId($contactId),
            $contact?->optionalString('email'),
            $given === '' ? null : $given,
            $family,
            $ledger,
            [...$node->only(self::DETAILS), 'points' => $node->child('points')?->raw()],
        );
    }
}
