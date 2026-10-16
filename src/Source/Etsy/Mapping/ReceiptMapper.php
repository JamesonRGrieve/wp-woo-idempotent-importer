<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A receipt (Etsy's order) → a WooCommerce order with Etsy's totals. Transactions become line items
 * (pointing at the listing and its variant, see Fields::variantId), shipping one shipping line, sales
 * tax and VAT order-level tax lines (Etsy doesn't split them per line), refunds WooCommerce refunds and
 * the seller's note and each shipment's tracking order notes.
 */
final class ReceiptMapper implements Mapper
{
    private const VERSION = 1;
    private const SHIPPED_STATUS = ['paid' => 'completed', 'partially refunded' => 'completed'];
    private const STATUS = [
        'open' => 'pending',
        'payment processing' => 'on-hold',
        'paid' => 'processing',
        'completed' => 'completed',
        'partially refunded' => 'processing',
        'fully refunded' => 'refunded',
        'canceled' => 'cancelled',
    ];
    private const TAXES = ['Sales tax' => 'total_tax_cost', 'VAT' => 'total_vat_cost'];

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
        $receiptId = $node->int('receipt_id');
        $buyerId = $node->optionalInt('buyer_user_id');
        $address = Fields::address($node);
        $giftWrap = Fields::moneyOrZero($node, 'gift_wrap_price');

        return new Desired(Kind::Order, $node->id(), [
            'name' => "#{$receiptId}",
            'number' => $receiptId,
            'created_at' => Fields::requiredTime($node, 'created_timestamp'),
            'currency' => $node->requiredChild('grandtotal')->string('currency_code'),
            'prices_include_tax' => false,
            'customer_gid' => $buyerId === null || $buyerId < 1 ? null : Fields::id('User', $buyerId),
            'email' => self::email($node->optionalString('buyer_email')),
            'phone' => null,
            'billing' => $address,
            'shipping' => $address,
            'tags' => [],
            'custom_attributes' => array_filter([
                'Gift' => $node->bool('is_gift') ? 'yes' : null,
                'Gift message' => Fields::text($node->optionalString('gift_message')),
                'Gift sender' => Fields::text($node->optionalString('gift_sender')),
            ], static fn(?string $value): bool => $value !== null && $value !== ''),
            'coupons' => [],
            'line_items' => array_map(self::lineItem(...), $node->list('transactions')),
            'shipping_lines' => [[
                'title' => self::shippingTitle($node),
                'code' => null,
                'source' => 'etsy',
                'total' => Fields::moneyOrZero($node, 'total_shipping_cost'),
                'taxes' => [],
            ]],
            'tax_lines' => self::taxLines($node),
            'subtotal' => Fields::moneyOrZero($node, 'subtotal'),
            'discount_total' => Fields::moneyOrZero($node, 'discount_amt'),
            'shipping_total' => Fields::moneyOrZero($node, 'total_shipping_cost'),
            'tax_total' => Decimal::sum(array_map(static fn(string $field): string => Fields::moneyOrZero($node, $field), array_values(self::TAXES))),
            'total' => Fields::moneyOrZero($node, 'grandtotal'),
            'status' => self::status($node->string('status'), $node->bool('is_shipped')),
            'financial_status' => $node->string('status'),
            'processed_at' => self::paidAt($node),
            'closed_at' => self::lastShipment($node),
            'customer_note' => Fields::text($node->optionalString('message_from_buyer')),
            'source_name' => 'etsy',
            'payment_method_title' => 'Etsy (' . $node->string('payment_method') . ')',
            'transaction_id' => null,
            'fees' => Decimal::isZero($giftWrap) ? [] : [['name' => 'Gift wrap', 'total' => $giftWrap, 'taxes' => []]],
            'refunds' => array_map(static fn(Node $refund): array => self::refund($receiptId, $refund), $node->list('refunds')),
            'notes' => self::notes($node),
        ]);
    }

    /** Etsy's receipt status (and whether it shipped) → a WooCommerce order status. */
    public static function status(string $status, bool $shipped): string
    {
        if ($shipped && isset(self::SHIPPED_STATUS[$status])) {
            return self::SHIPPED_STATUS[$status];
        }

        return self::STATUS[$status] ?? 'pending';
    }

    /** @return array<string, mixed> */
    private static function lineItem(Node $transaction): array
    {
        $listingId = $transaction->optionalInt('listing_id');
        $valueIds = [];
        $attributes = [];
        foreach ($transaction->list('variations') as $variation) {
            $valueId = $variation->optionalInt('value_id');
            if ($valueId !== null) {
                $valueIds[$variation->int('property_id')] = $valueId;
            }
            $attributes[(string) Fields::text($variation->string('formatted_name'))] = (string) Fields::text($variation->string('formatted_value'));
        }
        $quantity = $transaction->int('quantity');
        $subtotal = Decimal::multiply(Fields::moneyOrZero($transaction, 'price'), $quantity);

        return [
            'gid' => Fields::id('Transaction', $transaction->int('transaction_id')),
            'name' => (string) Fields::text($transaction->optionalString('title') ?? ''),
            'gift_card' => false,
            'product_gid' => $listingId === null ? null : Fields::id('Listing', $listingId),
            'variant_gid' => $listingId === null ? null : Fields::variantId($listingId, $valueIds),
            'sku' => $transaction->optionalString('sku'),
            'quantity' => $quantity,
            'taxable' => true,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'taxes' => [],
            'attributes' => $attributes,
        ];
    }

    /** @return list<array{title: string, rate: null, order_amount: string}> */
    private static function taxLines(Node $receipt): array
    {
        $lines = [];
        foreach (self::TAXES as $title => $field) {
            $amount = Fields::moneyOrZero($receipt, $field);
            if (!Decimal::isZero($amount)) {
                $lines[] = ['title' => $title, 'rate' => null, 'order_amount' => $amount];
            }
        }

        return $lines;
    }

    private static function shippingTitle(Node $receipt): string
    {
        foreach ($receipt->list('transactions') as $transaction) {
            $method = $transaction->optionalString('shipping_method');
            if ($method !== null) {
                return (string) Fields::text($method);
            }
        }

        return 'Shipping';
    }

    /** When the receipt was paid: the first transaction's paid time. */
    private static function paidAt(Node $receipt): ?string
    {
        foreach ($receipt->list('transactions') as $transaction) {
            $paid = Fields::time($transaction, 'paid_timestamp');
            if ($paid !== null) {
                return $paid;
            }
        }

        return null;
    }

    private static function lastShipment(Node $receipt): ?string
    {
        $times = array_map(static fn(Node $shipment): int => $shipment->int('shipment_notification_timestamp'), $receipt->list('shipments'));

        return $times === [] ? null : Fields::iso(max($times));
    }

    /**
     * Etsy refunds have no id; one is identified by its receipt and the time it was issued.
     *
     * @return array<string, mixed>
     */
    private static function refund(int $receiptId, Node $refund): array
    {
        $note = array_filter([Fields::text($refund->optionalString('reason')), Fields::text($refund->optionalString('note_from_issuer'))]);

        return [
            'gid' => Fields::id('Refund', $receiptId . '-' . $refund->int('created_timestamp')),
            'created_at' => Fields::requiredTime($refund, 'created_timestamp'),
            'note' => $note === [] ? null : implode(' — ', $note),
            'amount' => Fields::moneyOrZero($refund, 'amount'),
            'lines' => [],
        ];
    }

    /** @return list<array{gid: string, created_at: string, author: ?string, message: string}> */
    private static function notes(Node $receipt): array
    {
        $receiptId = $receipt->int('receipt_id');
        $notes = [];
        $sellerNote = Fields::text($receipt->optionalString('message_from_seller'));
        if ($sellerNote !== null && $sellerNote !== '') {
            $notes[] = ['gid' => Fields::id('SellerNote', $receiptId), 'created_at' => Fields::requiredTime($receipt, 'created_timestamp'), 'author' => 'Seller', 'message' => $sellerNote];
        }
        foreach ($receipt->list('shipments') as $index => $shipment) {
            $shipmentId = $shipment->optionalInt('receipt_shipping_id') ?? "{$receiptId}-{$index}";
            $notes[] = [
                'gid' => Fields::id('Shipment', $shipmentId),
                'created_at' => Fields::requiredTime($shipment, 'shipment_notification_timestamp'),
                'author' => null,
                'message' => trim("Etsy shipment: {$shipment->string('carrier_name')} {$shipment->string('tracking_code')}"),
            ];
        }

        return $notes;
    }

    private static function email(?string $email): ?string
    {
        return $email === null ? null : strtolower($email);
    }
}
