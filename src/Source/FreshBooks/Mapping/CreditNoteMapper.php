<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\FreshBooks\Mapping;

use IdempotentImporter\Mapping\CreditContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A credit note → a WooCommerce refund on the mirrored order of each invoice it was applied to (CreditContract): a
 * FreshBooks payment naming the credit note (`creditid`) applied that much of it to its invoice, so the refund is of
 * that amount, dated when it was applied, with the credit note's own lines kept on it. What is left of the credit
 * note is the client's store credit (StoreCreditMapper). A credit note applied to nothing has no refund.
 */
final class CreditNoteMapper implements Mapper
{
    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::Return;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): ?Desired
    {
        $number = Fields::text($node, 'credit_number') ?? (string) $node->int('creditid');
        $lines = self::lines($node);
        $refunds = [];
        foreach ($node->list('applications') as $payment) {
            $refunds[] = CreditContract::refund(
                Fields::id('CreditApplication', $payment->int('id')),
                Fields::id('Invoice', $payment->int('invoiceid')),
                Fields::moneyOrZero($payment, 'amount'),
                "Credit note {$number} applied",
                Fields::date($payment, 'date'),
                $lines,
            );
        }

        return CreditContract::refunds($node->id(), $number, Fields::SOURCE_LABEL, $refunds);
    }

    /**
     * A credit note's lines, as FreshBooks gives them.
     *
     * @return list<array<string, mixed>>
     */
    public static function lines(Node $note): array
    {
        $lines = [];
        foreach (is_array($note->raw()['lines'] ?? null) ? $note->list('lines') : [] as $line) {
            $lines[] = array_filter([
                'name' => Fields::text($line, 'name'),
                'description' => Fields::text($line, 'description'),
                'quantity' => Fields::text($line, 'qty'),
                'unit_price' => Fields::money($line, 'unit_cost'),
                'amount' => Fields::money($line, 'amount'),
                'taxes' => array_values(array_filter([Fields::text($line, 'taxName1'), Fields::text($line, 'taxName2')])),
            ], static fn(mixed $value): bool => $value !== null && $value !== []);
        }

        return $lines;
    }
}
