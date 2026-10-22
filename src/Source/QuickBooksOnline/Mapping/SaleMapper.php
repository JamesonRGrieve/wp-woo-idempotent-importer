<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\QuickBooksOnline\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/**
 * An invoice or a sales receipt (the reader says which, `record_type`) → a WooCommerce order with QuickBooks' amounts.
 *
 * - Number, transaction date, currency (the transaction's, else the company's home currency), the customer (a
 *   WooCommerce customer, with a placeholder email when it has none), billing and shipping addresses.
 * - Item lines (a bundle's lines too): the item, description, quantity and amount; the shipping item's line is the
 *   shipping. Discount lines make the discount; QuickBooks' tax lines, by tax rate, the taxes; its total the total.
 * - A sales receipt is paid when made: completed, with its payment method and reference. An invoice with nothing
 *   left to pay is completed, paid on its last payment's date; otherwise pending payment. Each payment applied to an
 *   invoice is a private order note (amount, method, reference, date, memo); the last one's method and reference are
 *   the order's.
 * - Its due date, terms, PO number, memo, private note, balance, class, location and statuses are meta.
 */
final class SaleMapper implements Mapper
{
    public const INVOICE = 'Invoice';
    public const SALES_RECEIPT = 'SalesReceipt';
    /** The item QuickBooks books a sale's shipping charge to. */
    public const SHIPPING_ITEM = 'SHIPPING_ITEM_ID';

    private const VERSION = 2;
    private const DETAILS = [
        'DueDate', 'SalesTermRef', 'PONumber', 'CustomerMemo', 'PrivateNote', 'Balance', 'ClassRef', 'DepartmentRef', 'EmailStatus', 'PrintStatus',
        'ExchangeRate', 'GlobalTaxCalculation', 'ShipDate', 'TrackingNum', 'ShipMethodRef', 'DepositToAccountRef', 'TxnStatus', 'Deposit',
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
        $receipt = $node->string('record_type') === self::SALES_RECEIPT;
        $customer = $node->child('customer');
        $lines = self::itemLines(is_array($node->raw()['Line'] ?? null) ? $node->list('Line') : []);
        $shipping = array_values(array_filter($lines, static fn(Node $line): bool => self::itemOf($line) === self::SHIPPING_ITEM));
        $items = array_values(array_filter($lines, static fn(Node $line): bool => self::itemOf($line) !== self::SHIPPING_ITEM));
        $discounts = array_values(array_filter(
            is_array($node->raw()['Line'] ?? null) ? $node->list('Line') : [],
            static fn(Node $line): bool => $line->optionalString('DetailType') === 'DiscountLineDetail',
        ));
        $payments = is_array($node->raw()['payments'] ?? null) ? $node->list('payments') : [];
        $last = $payments === [] ? null : $payments[count($payments) - 1];
        $paid = $receipt || (Decimal::isZero(Fields::amount($node, 'Balance') ?? '0') && Decimal::compare(Fields::amount($node, 'TotalAmt') ?? '0', '0') > 0);
        $currency = Fields::ref($node, 'CurrencyRef') ?? $node->optionalString('home_currency') ?? throw new SourceError('QuickBooks Online named no currency.');
        $number = Fields::text($node, 'DocNumber') ?? Fields::sourceId($node);
        $taxTotal = Decimal::normalise($node->child('TxnTaxDetail')?->optionalDecimal('TotalTax') ?? '0');

        return new Desired(Kind::Order, $node->id(), [
            'name' => $number,
            'created_at' => Fields::date($node, 'TxnDate') ?? Fields::created($node) ?? throw new SourceError("QuickBooks Online sale {$number} has no date."),
            'currency' => $currency,
            'prices_include_tax' => Fields::text($node, 'GlobalTaxCalculation') === 'TaxInclusive',
            'customer_gid' => $customer === null ? null : Fields::id('Customer', $customer->string('Id')),
            'email' => Fields::email($node, 'BillEmail'),
            'phone' => null,
            'billing' => $customer === null ? self::bare($node->child('BillAddr')) : CustomerMapper::address($customer, $node->child('BillAddr')),
            'shipping' => $customer === null ? self::bare($node->child('ShipAddr')) : CustomerMapper::address($customer, $node->child('ShipAddr'), false),
            'coupons' => [],
            'line_items' => array_map(static fn(Node $line, int $index): array => self::lineItem($line, Fields::sourceId($node), $index), $items, array_keys($items)),
            'shipping_lines' => array_map(static fn(Node $line): array => ['title' => Fields::text($line, 'Description') ?? 'Shipping', 'code' => null, 'total' => Decimal::normalise(Fields::amount($line, 'Amount') ?? '0'), 'taxes' => []], $shipping),
            'tax_lines' => self::taxLines($node),
            'discount_total' => Fields::sum(array_map(static fn(Node $line): string => Fields::amount($line, 'Amount') ?? '0', $discounts)),
            'shipping_total' => Fields::sum(array_map(static fn(Node $line): string => Fields::amount($line, 'Amount') ?? '0', $shipping)),
            'tax_total' => $taxTotal,
            'total' => Decimal::normalise(Fields::amount($node, 'TotalAmt') ?? '0'),
            'status' => $paid ? 'completed' : 'pending',
            'processed_at' => !$paid ? null : ($receipt ? Fields::date($node, 'TxnDate') : ($last === null ? null : Fields::date($last, 'TxnDate'))),
            'closed_at' => null,
            'customer_note' => null,
            'payment_method_title' => Fields::refName($receipt ? $node : ($last ?? $node), 'PaymentMethodRef'),
            'transaction_id' => Fields::text($receipt ? $node : ($last ?? $node), 'PaymentRefNum'),
            'fees' => [],
            'refunds' => [],
            'notes' => array_map(static fn(Node $payment): array => self::paymentNote($payment, $currency), $payments),
            'original_meta' => [],
            'sale' => [...Fields::details($node, self::DETAILS), 'type' => $receipt ? self::SALES_RECEIPT : self::INVOICE],
        ]);
    }

    /**
     * The item lines, a bundle's own lines in its place.
     *
     * @param list<Node> $lines
     *
     * @return list<Node>
     */
    private static function itemLines(array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            match ($line->optionalString('DetailType')) {
                'SalesItemLineDetail' => $items[] = $line,
                'GroupLineDetail' => $items = [...$items, ...self::itemLines(is_array($line->child('GroupLineDetail')?->raw()['Line'] ?? null) ? $line->requiredChild('GroupLineDetail')->list('Line') : [])],
                default => null,
            };
        }

        return $items;
    }

    private static function itemOf(Node $line): ?string
    {
        return $line->child('SalesItemLineDetail') === null ? null : Fields::ref($line->requiredChild('SalesItemLineDetail'), 'ItemRef');
    }

    /** @return array<string, mixed> */
    private static function lineItem(Node $line, string $saleId, int $index): array
    {
        $detail = $line->requiredChild('SalesItemLineDetail');
        $item = Fields::ref($detail, 'ItemRef');
        $quantity = Decimal::normalise(Fields::amount($detail, 'Qty') ?? '1');
        $amount = Decimal::normalise(Fields::amount($line, 'Amount') ?? '0');

        return [
            'gid' => Fields::id('SaleLine', $saleId . '-' . (Fields::text($line, 'Id') ?? (string) ($index + 1))),
            'name' => Fields::refName($detail, 'ItemRef') ?? Fields::text($line, 'Description') ?? 'Line ' . ($index + 1),
            'product_gid' => $item === null ? null : Fields::id('Item', $item),
            'variant_gid' => $item === null ? null : Fields::id('Variant', $item),
            'sku' => null,
            'quantity' => (int) Decimal::round($quantity, 0),
            'subtotal' => $amount,
            'total' => $amount,
            'taxes' => [],
            'attributes' => array_filter([
                'Description' => Fields::text($line, 'Description'),
                'Quantity' => Decimal::compare(Decimal::round($quantity, 0), $quantity) === 0 ? null : $quantity,
                'Unit price' => Fields::amount($detail, 'UnitPrice') === null ? null : Decimal::normalise((string) Fields::amount($detail, 'UnitPrice')),
                'Service date' => Fields::text($detail, 'ServiceDate'),
            ], static fn(?string $value): bool => $value !== null),
        ];
    }

    /**
     * QuickBooks' tax lines, one per tax rate (its name from the rate list), at their amounts.
     *
     * @return list<array<string, ?string>>
     */
    private static function taxLines(Node $sale): array
    {
        $detail = $sale->child('TxnTaxDetail');
        $names = $sale->child('tax_rates');
        $amounts = [];
        $rates = [];
        foreach ($detail !== null && is_array($detail->raw()['TaxLine'] ?? null) ? $detail->list('TaxLine') : [] as $line) {
            $tax = $line->child('TaxLineDetail');
            $rateId = $tax === null ? null : Fields::ref($tax, 'TaxRateRef');
            $title = ($rateId === null ? null : $names?->optionalString($rateId)) ?? ($rateId === null ? 'Tax' : "Tax rate {$rateId}");
            $amounts[$title][] = Fields::amount($line, 'Amount') ?? '0';
            $percent = $tax?->optionalDecimal('TaxPercent');
            $rates[$title] = $percent === null ? null : Decimal::shift($percent, -2);
        }
        $lines = [];
        foreach ($amounts as $title => $values) {
            $lines[] = ['title' => (string) $title, 'rate' => $rates[$title] ?? null, 'order_amount' => Fields::sum($values)];
        }

        return $lines;
    }

    /** @return array<string, string>|null an address alone, for a sale to no customer */
    private static function bare(?Node $address): ?array
    {
        $parts = Fields::storeAddress($address);

        return $parts === [] ? null : $parts;
    }

    /** @return array<string, ?string> */
    private static function paymentNote(Node $payment, string $currency): array
    {
        $parts = ['Payment of ' . Decimal::normalise($payment->decimal('applied')) . " {$currency}"];
        $method = Fields::refName($payment, 'PaymentMethodRef');
        if ($method !== null) {
            $parts[] = "by {$method}";
        }
        $reference = Fields::text($payment, 'PaymentRefNum');
        if ($reference !== null) {
            $parts[] = "(ref. {$reference})";
        }
        $date = Fields::text($payment, 'TxnDate');
        if ($date !== null) {
            $parts[] = "on {$date}";
        }
        $memo = Fields::text($payment, 'PrivateNote');

        return [
            'gid' => Fields::id('Payment', $payment->string('Id')),
            'author' => Fields::SOURCE_LABEL,
            'message' => implode(' ', $parts) . ($memo === null ? '.' : ": {$memo}"),
            'created_at' => Fields::date($payment, 'TxnDate') ?? Fields::created($payment) ?? throw new SourceError("QuickBooks Online payment {$payment->string('Id')} has no date."),
        ];
    }
}
