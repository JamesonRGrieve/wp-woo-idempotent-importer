<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\QuickBooksOnline\Mapping;

use IdempotentImporter\Mapping\CreditContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * Credit memos and refund receipts → WooCommerce refunds on mirrored orders (CreditContract).
 *
 * - A credit memo is applied to invoices by payments: a payment line linking the credit memo applies that much of
 *   it to the invoice the payment pays, so that is a refund of that amount on the invoice's order, dated with the
 *   payment, carrying the credit memo's own lines. A payment that pays several invoices doesn't say which the credit
 *   went to: that application is not guessed, it is left out of the refunds with a warning (it is still spent from
 *   the customer's store credit). What is left of the credit memo is store credit (StoreCreditMapper).
 * - A refund receipt refunds the sale it links (`LinkedTxn`, a sales receipt or invoice): a refund of its total on
 *   that sale's order, with its lines. One that links no sale is Unsupported: there is no order to refund.
 */
final class CreditMapper implements Mapper
{
    private const VERSION = 1;
    private const SALES = ['SalesReceipt', 'Invoice'];
    private const ITEM_LINE = 'SalesItemLineDetail';

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
        return str_contains($node->id(), '/RefundReceipt/') ? $this->refundReceipt($node) : $this->creditMemo($node);
    }

    private function creditMemo(Node $memo): ?Desired
    {
        $number = Fields::text($memo, 'DocNumber') ?? $memo->string('Id');
        $lines = self::lines($memo);
        $refunds = [];
        $warnings = [];
        foreach ($memo->list('applications') as $application) {
            $invoices = $application->strings('invoice_ids');
            if (count($invoices) !== 1) {
                $warnings[] = "payment {$application->string('payment_id')} applied {$application->string('amount')} of it to " . (count($invoices) === 0 ? 'no invoice' : 'several invoices (' . implode(', ', $invoices) . '), not saying which') . '; that part is not a refund.';

                continue;
            }
            $refunds[] = CreditContract::refund(
                Fields::id('CreditApplication', "{$memo->string('Id')}-{$application->string('payment_id')}"),
                Fields::id('Invoice', $invoices[0]),
                $application->string('amount'),
                "Credit memo {$number} applied",
                Fields::date($application, 'date'),
                $lines,
            );
        }

        return CreditContract::refunds($memo->id(), $number, Fields::SOURCE_LABEL, $refunds, $warnings);
    }

    private function refundReceipt(Node $receipt): Desired
    {
        $number = Fields::text($receipt, 'DocNumber') ?? $receipt->string('Id');
        $sale = null;
        foreach (is_array($receipt->raw()['LinkedTxn'] ?? null) ? $receipt->list('LinkedTxn') : [] as $linked) {
            if (in_array($linked->optionalString('TxnType'), self::SALES, true)) {
                $sale ??= [$linked->string('TxnType'), $linked->string('TxnId')];
            }
        }
        if ($sale === null) {
            throw new Unsupported("refund receipt {$number} links no sale, so there is no mirrored order to refund.");
        }
        $refund = CreditContract::refund(
            Fields::id('RefundReceiptRefund', $receipt->string('Id')),
            Fields::id($sale[0], $sale[1]),
            Fields::amount($receipt, 'TotalAmt') ?? '0',
            trim("Refund receipt {$number} " . (Fields::refName($receipt, 'PaymentMethodRef') ?? '')),
            Fields::date($receipt, 'TxnDate'),
            self::lines($receipt),
        );

        return CreditContract::refunds($receipt->id(), $number, Fields::SOURCE_LABEL, [$refund]) ?? throw new Unsupported("refund receipt {$number} has no refund.");
    }

    /**
     * A credit memo's or refund receipt's item lines, as QuickBooks gives them.
     *
     * @return list<array<string, mixed>>
     */
    public static function lines(Node $transaction): array
    {
        $lines = [];
        foreach (is_array($transaction->raw()['Line'] ?? null) ? $transaction->list('Line') : [] as $line) {
            $detail = $line->child(self::ITEM_LINE);
            if (Fields::text($line, 'DetailType') !== self::ITEM_LINE || $detail === null) {
                continue;
            }
            $lines[] = array_filter([
                'name' => Fields::refName($detail, 'ItemRef'),
                'item_id' => Fields::ref($detail, 'ItemRef'),
                'description' => Fields::text($line, 'Description'),
                'quantity' => Fields::amount($detail, 'Qty'),
                'unit_price' => Fields::amount($detail, 'UnitPrice'),
                'amount' => Fields::amount($line, 'Amount'),
            ], static fn(?string $value): bool => $value !== null);
        }

        return $lines;
    }
}
