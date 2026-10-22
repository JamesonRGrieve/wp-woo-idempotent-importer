<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\FreshBooks\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/**
 * An invoice with its lines and the payments made on it (the reader adds them, `payments`) → a WooCommerce order
 * with FreshBooks' amounts.
 *
 * - Number, issue date, currency, the client (a WooCommerce customer, with a placeholder email when it has none),
 *   and the address on the invoice as the billing address, else the client's.
 * - Each line: its name, quantity, and quantity × unit cost as its total. FreshBooks lines name no item, so a line
 *   links to no product.
 * - The discount is FreshBooks' `discount_total`.
 * - Each tax is charged on the lines it is on, after the invoice's percentage discount, rounded to the cent. A
 *   line's `taxAmount` is the tax's percentage (www.freshbooks.com/api/invoices). If those don't make the amount
 *   exactly, a single tax of the difference is booked instead, with a warning.
 * - The total is FreshBooks' amount.
 * - Status: nothing outstanding → completed, paid on the last payment's date; disputed → on hold; anything else
 *   (draft, sent, viewed, partial, overdue) → pending payment. FreshBooks' own statuses are meta.
 * - Each payment is a private order note (amount, method, date, note, whether it came from a client credit), and
 *   the last payment's method is the payment method.
 * - Its due date, PO number, notes, terms, statuses, paid and outstanding amounts, discount and accounting id are
 *   meta.
 */
final class InvoiceMapper implements Mapper
{
    public const TAX_FALLBACK = 'Tax';
    public const TAX_MISMATCH = 'FreshBooks invoice %s: its taxes, figured per line, come to %s, not %s; booked as one tax.';

    private const VERSION = 2;
    private const MONEY_PLACES = 2;
    private const PERCENT_PLACES = 2;
    private const HUNDRED = '100';
    private const TAX_SLOTS = [['taxName1', 'taxAmount1'], ['taxName2', 'taxAmount2']];
    private const DISPUTED = ['disputed'];
    private const DETAILS = [
        'due_date', 'po_number', 'notes', 'terms', 'status', 'v3_status', 'display_status', 'payment_status', 'dispute_status', 'paid',
        'outstanding', 'discount_value', 'discount_description', 'description', 'vat_name', 'vat_number', 'accountid', 'date_paid',
    ];

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
        $number = Fields::text($node, 'invoice_number') ?? Fields::sourceId($node);
        $currency = Fields::text($node, 'currency_code') ?? $node->requiredChild('amount')->string('code');
        $lines = is_array($node->raw()['lines'] ?? null) ? $node->list('lines') : [];
        $subtotal = Decimal::sum(array_map(static fn(Node $line): string => Fields::moneyOrZero($line, 'amount'), $lines));
        $discount = Fields::moneyOrZero($node, 'discount_total');
        $total = Fields::moneyOrZero($node, 'amount');
        $taxTotal = Decimal::subtract($total, Decimal::subtract($subtotal, $discount));
        [$taxLines, $warnings] = self::taxLines($number, $lines, Fields::text($node, 'discount_value') ?? '0', $taxTotal);
        $payments = is_array($node->raw()['payments'] ?? null) ? $node->list('payments') : [];
        $last = $payments === [] ? null : $payments[count($payments) - 1];
        $status = self::status($node, $total);
        $client = $node->child('client');

        return new Desired(Kind::Order, $node->id(), [
            'name' => $number,
            'created_at' => Fields::date($node, 'create_date'),
            'currency' => $currency,
            'prices_include_tax' => false,
            'customer_gid' => $client === null ? null : Fields::id('Customer', $client->int('id')),
            'email' => null,
            'phone' => null,
            'billing' => self::billing($node) ?? ($client === null ? null : self::clientBilling($client)),
            'shipping' => null,
            'coupons' => [],
            'line_items' => array_map(static fn(Node $line, int $index): array => self::lineItem($line, Fields::sourceId($node), $index), $lines, array_keys($lines)),
            'shipping_lines' => [],
            'tax_lines' => $taxLines,
            'discount_total' => $discount,
            'shipping_total' => '0',
            'tax_total' => $taxTotal,
            'total' => $total,
            'status' => $status,
            'processed_at' => $status === 'completed' ? ($last === null ? Fields::date($node, 'date_paid') : Fields::date($last, 'date')) : null,
            'closed_at' => null,
            'customer_note' => null,
            'payment_method_title' => $last === null ? null : Fields::text($last, 'type'),
            'transaction_id' => null,
            'fees' => [],
            'refunds' => [],
            'notes' => array_map(self::paymentNote(...), $payments),
            'original_meta' => [],
            'invoice' => Fields::details($node, self::DETAILS),
        ], warnings: $warnings);
    }

    private static function status(Node $invoice, string $total): string
    {
        if (in_array(Fields::text($invoice, 'v3_status'), self::DISPUTED, true)) {
            return 'on-hold';
        }
        $outstanding = Fields::money($invoice, 'outstanding');

        return $outstanding !== null && Decimal::isZero($outstanding) && Decimal::compare($total, '0') > 0 ? 'completed' : 'pending';
    }

    /**
     * One tax line per tax named on the lines, at its percentage of the discounted lines it is on; a single tax
     * of the exact difference when those don't make it.
     *
     * @param list<Node> $lines
     *
     * @return array{0: list<array<string, ?string>>, 1: list<string>}
     */
    private static function taxLines(string $number, array $lines, string $discountPercent, string $taxTotal): array
    {
        $bases = [];
        $percents = [];
        foreach ($lines as $line) {
            foreach (self::TAX_SLOTS as [$nameField, $percentField]) {
                $name = Fields::text($line, $nameField);
                $percent = Fields::text($line, $percentField);
                if ($name === null || $percent === null || Decimal::isZero($percent)) {
                    continue;
                }
                $bases[$name][] = Fields::moneyOrZero($line, 'amount');
                $percents[$name] = Decimal::normalise($percent);
            }
        }
        $kept = Decimal::subtract(self::HUNDRED, $discountPercent);
        $taxLines = [];
        foreach ($bases as $name => $amounts) {
            $tax = Decimal::round(Decimal::shift(Decimal::product(Decimal::product(Decimal::sum($amounts), $kept), $percents[$name]), -2 * self::PERCENT_PLACES), self::MONEY_PLACES);
            $taxLines[] = ['title' => $name, 'rate' => Decimal::shift($percents[$name], -self::PERCENT_PLACES), 'order_amount' => $tax];
        }
        $figured = Decimal::sum(array_map(static fn(array $line): string => (string) $line['order_amount'], $taxLines));
        if (Decimal::compare($figured, $taxTotal) === 0) {
            return [$taxLines, []];
        }
        $single = Decimal::isZero($taxTotal) ? [] : [['title' => self::TAX_FALLBACK, 'rate' => null, 'order_amount' => $taxTotal]];

        return [$single, [sprintf(self::TAX_MISMATCH, $number, $figured, $taxTotal)]];
    }

    /** @return array<string, mixed> */
    private static function lineItem(Node $line, string $invoiceId, int $index): array
    {
        $quantity = Fields::text($line, 'qty') ?? '1';
        $amount = Fields::moneyOrZero($line, 'amount');
        $description = Fields::text($line, 'description');

        return [
            'gid' => Fields::id('InvoiceLine', $line->optionalInt('lineid') ?? "{$invoiceId}-" . ($index + 1)),
            'name' => Fields::text($line, 'name') ?? $description ?? 'Line ' . ($index + 1),
            'product_gid' => null,
            'variant_gid' => null,
            'sku' => null,
            'quantity' => (int) Decimal::round($quantity, 0),
            'subtotal' => $amount,
            'total' => $amount,
            'taxes' => [],
            'attributes' => array_filter([
                'Description' => $description,
                'Quantity' => Decimal::compare(Decimal::round($quantity, 0), Decimal::normalise($quantity)) === 0 ? null : Decimal::normalise($quantity),
                'Unit cost' => Fields::money($line, 'unit_cost'),
            ], static fn(?string $value): bool => $value !== null),
        ];
    }

    /** @return array<string, string>|null the address on the invoice */
    private static function billing(Node $invoice): ?array
    {
        $billing = array_filter([
            'first_name' => Fields::text($invoice, 'fname'),
            'last_name' => Fields::text($invoice, 'lname'),
            'company' => Fields::text($invoice, 'organization') ?? Fields::text($invoice, 'current_organization'),
            'address_1' => Fields::text($invoice, 'street'),
            'address_2' => Fields::text($invoice, 'street2'),
            'city' => Fields::text($invoice, 'city'),
            'state' => Fields::text($invoice, 'province'),
            'postcode' => Fields::text($invoice, 'code'),
            'country' => Fields::text($invoice, 'country'),
        ], static fn(?string $value): bool => $value !== null);

        return $billing === [] ? null : $billing;
    }

    /** @return array<string, string>|null a client's name, organization and billing address */
    private static function clientBilling(Node $client): ?array
    {
        $billing = array_filter([
            'first_name' => Fields::text($client, 'fname'),
            'last_name' => Fields::text($client, 'lname'),
            'company' => Fields::text($client, 'organization'),
            ...Fields::storeAddress($client),
            'phone' => Fields::phone($client),
        ], static fn(?string $value): bool => $value !== null);

        return $billing === [] ? null : $billing;
    }

    /** @return array<string, ?string> */
    private static function paymentNote(Node $payment): array
    {
        $amount = $payment->child('amount')?->optionalString('amount') ?? '0';
        $code = $payment->child('amount')?->optionalString('code') ?? '';
        $parts = [trim("Payment of {$amount} {$code}")];
        $type = Fields::text($payment, 'type');
        if ($type !== null) {
            $parts[] = "by {$type}";
        }
        $date = Fields::text($payment, 'date');
        if ($date !== null) {
            $parts[] = "on {$date}";
        }
        $message = implode(' ', $parts) . (($payment->raw()['from_credit'] ?? false) === true ? ', from the client\'s credit' : '');
        $note = Fields::text($payment, 'note');

        return [
            'gid' => Fields::id('Payment', $payment->int('id')),
            'author' => Fields::SOURCE_LABEL,
            'message' => $note === null ? "{$message}." : "{$message}: {$note}",
            'created_at' => Fields::date($payment, 'date') ?? throw new SourceError("FreshBooks payment {$payment->int('id')} has no date."),
        ];
    }
}
