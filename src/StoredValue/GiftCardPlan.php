<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\StoredValue;

use IdempotentImporter\Support\Decimal;

/**
 * What to do in Square to bring a mirrored card to its source balance, moving only by what the source
 * changed since the last sync (so money spent on the card in Square is never put back):
 *
 * - a card Square holds as PENDING (never loaded) is activated with the whole balance;
 * - a drop at the source is an ADJUST_DECREMENT, at most what the card still holds in Square (anything
 *   more was spent in both stores, and is reported, never taken below zero);
 * - a rise at the source is an ADJUST_INCREMENT only when the operator allows it, since Square charges a
 *   load fee on it in the US, Canada and Australia; otherwise it is held and reported;
 * - a disabled or expired source card carries no balance into Square: it isn't activated, and a card
 *   already loaded is brought down to zero by the same drop rule.
 *
 * All amounts are exact decimal strings in the card's currency.
 */
final readonly class GiftCardPlan
{
    public const ACTIVATE = 'ACTIVATE';
    public const DECREMENT = 'ADJUST_DECREMENT';
    public const INCREMENT = 'ADJUST_INCREMENT';
    public const PENDING = 'PENDING';
    public const ACTIVE = 'ACTIVE';

    /**
     * @param string|null $step one of the activity types above, or null when nothing is written
     * @param string $loaded the source balance Square holds once the step is done
     * @param string|null $held why (part of) the change isn't written, for the ledger and the log
     */
    private function __construct(public ?string $step, public string $amount, public string $loaded, public ?string $held) {}

    /**
     * @param string $target the source balance (0 for a disabled or expired card)
     * @param string $loaded the source balance last brought into Square
     * @param string $squareState the card's state in Square
     * @param string $squareBalance what the card holds in Square now
     */
    public static function next(string $target, string $loaded, string $squareState, string $squareBalance, bool $loadIncreases): self
    {
        if ($squareState === self::PENDING) {
            return Decimal::compare($target, '0') > 0
                ? new self(self::ACTIVATE, $target, $target, null)
                : new self(null, '0', '0', null);
        }
        $change = Decimal::subtract($target, $loaded);
        $direction = Decimal::compare($change, '0');
        if ($direction === 0) {
            return new self(null, '0', $loaded, null);
        }
        if ($squareState !== self::ACTIVE) {
            return new self(null, '0', $loaded, "The source balance changed by {$change}, but the card is {$squareState} in Square.");
        }
        if ($direction > 0) {
            return $loadIncreases
                ? new self(self::INCREMENT, $change, $target, null)
                : new self(null, '0', $loaded, "The source balance rose by {$change}; loading it into Square is switched off (Square charges a load fee).");
        }
        $drop = Decimal::subtract('0', $change);
        if (Decimal::compare($drop, $squareBalance) <= 0) {
            return new self(self::DECREMENT, $drop, $target, null);
        }
        $shortfall = Decimal::subtract($drop, $squareBalance);
        $held = "The source balance fell by {$drop} but the card holds only {$squareBalance} in Square: {$shortfall} was spent in both stores.";

        return Decimal::isZero($squareBalance)
            ? new self(null, '0', $target, $held)
            : new self(self::DECREMENT, $squareBalance, $target, $held);
    }
}
