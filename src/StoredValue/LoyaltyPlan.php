<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\StoredValue;

/**
 * The AdjustLoyaltyPoints that brings a Square loyalty account to its source balance, moving only by what
 * the source changed since the last sync (points earned or spent in Square stay as they are). A drop bigger
 * than the account holds in Square was spent in both programs: the account is taken to zero, never below
 * (Square refuses a negative balance), and the rest is reported.
 */
final readonly class LoyaltyPlan
{
    /**
     * @param int $points the adjustment (signed; 0 for none)
     * @param int $loaded the source balance Square reflects once it is made
     */
    private function __construct(public int $points, public int $loaded, public ?string $held) {}

    public static function next(int $target, int $loaded, int $squareBalance): self
    {
        $change = $target - $loaded;
        if ($change >= 0 || -$change <= $squareBalance) {
            return new self($change, $target, null);
        }
        $shortfall = -$change - $squareBalance;

        return new self(-$squareBalance, $target, 'The source balance fell by ' . -$change . " points but the account holds only {$squareBalance} in Square: {$shortfall} were spent in both programs.");
    }
}
