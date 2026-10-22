<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Support\Decimal;

/**
 * The shared contract of an accounting system's credits to a customer (FreshBooks credit notes, QuickBooks credit
 * memos and refund receipts), so each source speaks it the same way (operator decision of 2026-10-21):
 *
 * - The part of a credit applied to an invoice is a WooCommerce refund on that invoice's mirrored order, of the
 *   amount applied, carrying the credit's own lines (a credit's lines name no invoice line, so they are kept on the
 *   refund, never matched to the order's by guesswork). A refund receipt is a refund on its sale's order.
 * - The unapplied balance is store credit: one Square gift card per customer (GiftCardContract, through the existing
 *   writer and its fail-safes: increases are held, the ledger is kept), its balance the sum of what is left of each
 *   credit, its ledger each credit issued and each application spent from it.
 *
 * Money is exact decimal strings; times ISO 8601 UTC (or a date).
 */
final class CreditContract
{
    private const ISSUE = 'issue';
    private const SPENT = 'redeem';

    /**
     * One refund on a mirrored order.
     *
     * @param list<array<string, mixed>> $creditLines the credit's own lines (name, description, quantity, unit price, amount)
     *
     * @return array{gid: string, order_gid: string, refund_gids: list<string>, lines: list<array<string, mixed>>, amount: string, note: ?string, created_at: ?string, credit_lines: list<array<string, mixed>>}
     */
    public static function refund(string $gid, string $orderGid, string $amount, ?string $note, ?string $at, array $creditLines): array
    {
        return [
            'gid' => $gid,
            'order_gid' => $orderGid,
            'refund_gids' => [],
            'lines' => [],
            'amount' => Decimal::normalise($amount),
            'note' => $note,
            'created_at' => $at,
            'credit_lines' => $creditLines,
        ];
    }

    /**
     * A credit's refunds (ReturnWriter); null when nothing of it is applied to a mirrored sale, so an earlier
     * refund is retired.
     *
     * @param list<array{gid: string, order_gid: string, refund_gids: list<string>, lines: list<array<string, mixed>>, amount: string, note: ?string, created_at: ?string, credit_lines: list<array<string, mixed>>}> $refunds
     * @param list<string> $warnings
     */
    public static function refunds(string $gid, string $name, string $sourceName, array $refunds, array $warnings = []): ?Desired
    {
        if ($refunds === []) {
            return null;
        }

        return new Desired(Kind::Return, $gid, [
            'name' => $name,
            'source_name' => $sourceName,
            'order_gids' => array_values(array_unique(array_map(static fn(array $refund): string => $refund['order_gid'], $refunds))),
            'refunds' => $refunds,
        ], [], $warnings);
    }

    /**
     * A credit and how it was used, for a customer's store credit.
     *
     * @param list<array{gid: string, at: ?string, amount: string, reference: ?string}> $applications
     *
     * @return array{gid: string, number: ?string, at: ?string, amount: string, applications: list<array{gid: string, at: ?string, amount: string, reference: ?string}>}
     */
    public static function credit(string $gid, ?string $number, ?string $at, string $amount, array $applications): array
    {
        return ['gid' => $gid, 'number' => $number, 'at' => $at, 'amount' => Decimal::normalise($amount), 'applications' => $applications];
    }

    /** @return array{gid: string, at: ?string, amount: string, reference: ?string} */
    public static function application(string $gid, ?string $at, string $amount, ?string $reference): array
    {
        return ['gid' => $gid, 'at' => $at, 'amount' => Decimal::normalise($amount), 'reference' => $reference];
    }

    /**
     * A customer's store credit: what is left of their credits, as a Square gift card (GiftCardContract) with no
     * code of its own (a new number, shown in their account), its ledger every credit issued and every application.
     * A placeholder email is never given to Square.
     *
     * @param list<array{gid: string, number: ?string, at: ?string, amount: string, applications: list<array{gid: string, at: ?string, amount: string, reference: ?string}>}> $credits
     *
     * @throws Unsupported when more was applied than a credit holds
     */
    public static function storeCredit(string $gid, Settings $settings, string $currency, string $customerGid, ?string $email, ?string $givenName, ?string $familyName, array $credits): Desired
    {
        $ledger = [];
        $balance = '0';
        foreach ($credits as $credit) {
            $ledger[] = GiftCardContract::entry($credit['gid'], $credit['at'], self::ISSUE, $credit['amount'], null, $credit['number']);
            $left = $credit['amount'];
            foreach ($credit['applications'] as $application) {
                $ledger[] = GiftCardContract::entry($application['gid'], $application['at'], self::SPENT, Decimal::subtract('0', $application['amount']), null, $application['reference']);
                $left = Decimal::subtract($left, $application['amount']);
            }
            if (Decimal::compare($left, '0') < 0) {
                throw new Unsupported("more was applied from credit {$credit['gid']} than it holds.");
            }
            $balance = Decimal::sum([$balance, $left]);
        }

        return GiftCardContract::desired(
            $gid,
            $settings,
            GiftCardContract::STORE_CREDIT,
            null,
            null,
            $currency,
            $balance,
            GiftCardContract::ACTIVE,
            $customerGid,
            $email === null || PlaceholderEmail::isPlaceholder($email) ? null : $email,
            $givenName,
            $familyName,
            $ledger,
            [],
        );
    }
}
