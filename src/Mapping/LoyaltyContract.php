<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use IdempotentImporter\Model\Kind;

/**
 * The shared field contract of a loyalty account, whatever the source, which LoyaltyWriter reads: its points
 * balance, the mirrored customer it belongs to (Square enrols by phone, taken from that customer), the
 * customer's email and name (to find or create their Square customer), and the source's own history, oldest
 * first, each entry's points signed (a redemption is negative).
 */
final class LoyaltyContract
{
    /**
     * @param list<array{gid: string, at: ?string, kind: string, points: int, note: ?string}> $ledger
     * @param array<array-key, mixed> $details the source's other fields, kept with the account
     */
    public static function desired(string $gid, int $points, ?string $customerGid, ?string $email, ?string $givenName, ?string $familyName, array $ledger, array $details): Desired
    {
        return new Desired(Kind::LoyaltyAccount, $gid, [
            'points' => $points,
            'customer_gid' => $customerGid,
            'email' => $email === null ? null : strtolower($email),
            'given_name' => $givenName,
            'family_name' => $familyName,
            'ledger' => $ledger,
            'details' => $details,
        ]);
    }

    /** @return array{gid: string, at: ?string, kind: string, points: int, note: ?string} */
    public static function entry(string $gid, ?string $at, string $kind, int $points, ?string $note = null): array
    {
        return ['gid' => $gid, 'at' => $at, 'kind' => $kind, 'points' => $points, 'note' => $note];
    }
}
