<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wave\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/**
 * An invoice with its items, taxes and payments → a WooCommerce order with Wave's amounts.
 *
 * - Number, invoice date, currency, the customer (a WooCommerce customer, with a placeholder email when it has
 *   none), and the customer's name and address as the billing address.
 * - Each item: its product, description, quantity and subtotal, and the taxes Wave computed on it, by sales tax
 *   (its abbreviation, at its rate).
 * - Wave's discount total, tax total and total.
 * - Status: paid or overpaid → completed, on the last payment's date; anything else (draft, saved, sent, viewed,
 *   unpaid, partial, overdue) → pending payment. Wave's status is meta.
 * - Each active payment is a private order note (amount, method, date, memo); the last one's method is the payment
 *   method and its transaction id the transaction id. A removed (inactive) payment is left out.
 * - Its title, subhead, PO number, due date, memo, footer, discounts, exchange rate and amounts paid and due are meta.
 */
final class InvoiceMapper implements Mapper
{
    private const VERSION = 2;
    private const PAID = ['PAID', 'OVERPAID'];
    private const DETAILS = ['status', 'title', 'subhead', 'poNumber', 'dueDate', 'memo', 'footer', 'discounts', 'exchangeRate', 'amountPaid', 'amountDue', 'wave_id'];

    public function kind(): Kind
    {
        return Kind::Order;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $customer = $node->requiredChild('customer');
        $items = is_array($node->raw()['items'] ?? null) ? $node->list('items') : [];
        $payments = array_values(array_filter(
            is_array($node->raw()['payments'] ?? null) ? $node->list('payments') : [],
            static fn(Node $payment): bool => ($payment->raw()['active'] ?? true) !== false,
        ));
        usort($payments, static fn(Node $a, Node $b): int => [Fields::text($a, 'paymentDate') ?? '', $a->string('id')] <=> [Fields::text($b, 'paymentDate') ?? '', $b->string('id')]);
        $last = $payments === [] ? null : $payments[count($payments) - 1];
        $paid = in_array($node->string('status'), self::PAID, true);
        $currency = $node->requiredChild('currency')->string('code');
        $number = Fields::text($node, 'invoiceNumber') ?? Fields::recordId($node->string('wave_id'));

        return new Desired(Kind::Order, $node->id(), [
            'name' => $number,
            'created_at' => Fields::date($node, 'invoiceDate') ?? Fields::time($node, 'createdAt') ?? throw new SourceError("Wave invoice {$number} has no date."),
            'currency' => $currency,
            'prices_include_tax' => false,
            'customer_gid' => Fields::linked('Customer', $customer),
            'email' => null,
            'phone' => null,
            'billing' => CustomerMapper::billing($customer),
            'shipping' => null,
            'coupons' => [],
            'line_items' => array_map(static fn(Node $item, int $index): array => self::lineItem($item, Fields::recordId($node->string('wave_id')), $index), $items, array_keys($items)),
            'shipping_lines' => [],
            'tax_lines' => self::taxLines($items),
            'discount_total' => Fields::money($node, 'discountTotal'),
            'shipping_total' => '0',
            'tax_total' => Fields::money($node, 'taxTotal'),
            'total' => Fields::money($node, 'total'),
            'status' => $paid ? 'completed' : 'pending',
            'processed_at' => $paid && $last !== null ? Fields::date($last, 'paymentDate') : null,
            'closed_at' => null,
            'customer_note' => null,
            'payment_method_title' => $last === null ? null : Fields::text($last, 'paymentMethod'),
            'transaction_id' => $last === null ? null : Fields::text($last, 'transactionId'),
            'fees' => [],
            'refunds' => [],
            'notes' => array_map(static fn(Node $payment): array => self::paymentNote($payment, $currency), $payments),
            'original_meta' => [],
            'invoice' => Fields::details($node, self::DETAILS),
        ]);
    }

    /** @return array<string, mixed> */
    private static function lineItem(Node $item, string $invoiceId, int $index): array
    {
        $product = $item->child('product');
        $quantity = Decimal::normalise(Fields::text($item, 'quantity') ?? '1');
        $subtotal = Fields::money($item, 'subtotal');
        $productId = $product === null ? null : Fields::recordId($product->string('id'));

        return [
            'gid' => Fields::id('InvoiceItem', "{$invoiceId}-" . ($index + 1)),
            'name' => $product?->optionalString('name') ?? Fields::text($item, 'description') ?? 'Item ' . ($index + 1),
            'product_gid' => $productId === null ? null : Fields::id('Product', $productId),
            'variant_gid' => $productId === null ? null : Fields::id('Variant', $productId),
            'sku' => null,
            'quantity' => (int) Decimal::round($quantity, 0),
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'taxes' => array_values(array_filter(array_map(
                static fn(Node $tax): array => ['title' => self::taxTitle($tax->requiredChild('salesTax')), 'rate' => $tax->requiredChild('salesTax')->optionalDecimal('rate'), 'amount' => Fields::money($tax, 'amount')],
                $item->list('taxes'),
            ), static fn(array $tax): bool => !Decimal::isZero($tax['amount']))),
            'attributes' => array_filter([
                'Description' => Fields::text($item, 'description'),
                'Quantity' => Decimal::compare(Decimal::round($quantity, 0), $quantity) === 0 ? null : $quantity,
                'Unit price' => Fields::text($item, 'unitPrice') === null ? null : Decimal::normalise((string) Fields::text($item, 'unitPrice')),
            ], static fn(?string $value): bool => $value !== null),
        ];
    }

    /**
     * One tax line per sales tax on the items, at its rate (the amounts are the items' own).
     *
     * @param list<Node> $items
     *
     * @return list<array<string, ?string>>
     */
    private static function taxLines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            foreach ($item->list('taxes') as $tax) {
                $salesTax = $tax->requiredChild('salesTax');
                $lines[self::taxTitle($salesTax)] ??= ['title' => self::taxTitle($salesTax), 'rate' => $salesTax->optionalDecimal('rate'), 'order_amount' => null];
            }
        }

        return array_values($lines);
    }

    private static function taxTitle(Node $salesTax): string
    {
        return Fields::text($salesTax, 'abbreviation') ?? $salesTax->string('name');
    }

    /** @return array<string, ?string> */
    private static function paymentNote(Node $payment, string $currency): array
    {
        $parts = ['Payment of ' . (Fields::text($payment, 'amount') ?? '0') . " {$currency}"];
        $method = Fields::text($payment, 'paymentMethod');
        if ($method !== null) {
            $parts[] = "by {$method}";
        }
        $date = Fields::text($payment, 'paymentDate');
        if ($date !== null) {
            $parts[] = "on {$date}";
        }
        $memo = Fields::text($payment, 'memo');

        return [
            'gid' => Fields::id('Payment', Fields::recordId($payment->string('id'))),
            'author' => Fields::SOURCE_LABEL,
            'message' => implode(' ', $parts) . ($memo === null ? '.' : ": {$memo}"),
            'created_at' => Fields::date($payment, 'paymentDate') ?? Fields::time($payment, 'createdAt') ?? throw new SourceError('A Wave payment has no date.'),
        ];
    }
}
