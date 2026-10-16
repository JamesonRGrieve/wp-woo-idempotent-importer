<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Squarespace\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/**
 * An order → a WooCommerce order with Squarespace's totals. Lines point at the product and its variant (see
 * Fields::variantKey) with their option choices and product-form answers; shipping lines, promo codes as
 * coupon lines, refunds (from the order's payment transactions) as WooCommerce refunds, fulfillments
 * (carrier, tracking) and internal notes as order notes. Squarespace reports tax per sale and shipping item
 * of its transactions, without saying which order line an item is, so each tax becomes an order-level tax
 * line (its total and rate); an order without transactions gets one from its tax total. The fulfillment
 * and payment states map to WooCommerce statuses (see status()). Test-mode orders map to nothing. The
 * channel, external reference, payment and fulfillment states, refunded total, checkout form answers and
 * shipping option are mirrored as meta.
 */
final class OrderMapper implements Mapper
{
    public const SOURCE = 'squarespace';

    private const VERSION = 1;
    private const INCLUSIVE = 'INCLUSIVE';
    private const GIFT_CARD = 'GIFT_CARD';
    private const PERCENT_PLACES = 2;
    private const DETAILS = [
        'orderNumber', 'channel', 'channelName', 'externalOrderReference', 'paymentState', 'fulfillmentStatus',
        'refundedTotal', 'subtotal', 'formSubmission', 'shippingOptionName', 'shippingOptionServiceType',
        'priceTaxInterpretation',
    ];

    public function kind(): Kind
    {
        return Kind::Order;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): ?Desired
    {
        if (($node->raw()['testmode'] ?? false) === true) {
            return null;
        }
        $orderId = Fields::sourceId($node);
        $documents = array_values(array_filter($node->list('transactions'), static fn(Node $document): bool => ($document->raw()['voided'] ?? false) !== true));
        $payments = [];
        foreach ($node->list('transactions') as $document) {
            $payments = [...$payments, ...$document->list('payments')];
        }
        $billing = $node->child('billingAddress');
        $email = $node->optionalString('customerEmail');
        $profile = $node->child('customer_profile');
        $grandTotal = $node->requiredChild('grandTotal');

        return new Desired(Kind::Order, $node->id(), [
            'name' => '#' . ($node->optionalString('orderNumber') ?? $orderId),
            'created_at' => Fields::requiredTime($node, 'createdOn'),
            'currency' => Fields::currency($grandTotal) ?? throw new SourceError("Squarespace order {$orderId} has no currency."),
            'prices_include_tax' => $node->optionalString('priceTaxInterpretation') === self::INCLUSIVE,
            'customer_gid' => $profile !== null && ProfileMapper::isCustomer($profile) ? Fields::profileId($profile->string('id')) : null,
            'email' => $email === null ? null : strtolower($email),
            'phone' => $billing?->optionalString('phone'),
            'billing' => Fields::address($billing),
            'shipping' => Fields::address($node->child('shippingAddress')),
            'tags' => [],
            'coupons' => self::coupons($node),
            'line_items' => array_map(self::lineItem(...), $node->list('lineItems')),
            'shipping_lines' => self::shippingLines($node),
            'tax_lines' => self::taxLines($node, $documents),
            'discount_total' => Fields::moneyOrZero($node, 'discountTotal'),
            'shipping_total' => Fields::moneyOrZero($node, 'shippingTotal'),
            'tax_total' => Fields::moneyOrZero($node, 'taxTotal'),
            'total' => Fields::moneyOrZero($node, 'grandTotal'),
            'status' => self::status($node->optionalString('fulfillmentStatus') ?? '', $node->optionalString('paymentState')),
            'processed_at' => self::paidAt($payments),
            'closed_at' => Fields::time($node, 'fulfilledOn'),
            'customer_note' => null,
            'source_name' => self::SOURCE,
            'payment_method_title' => self::paymentMethod($payments),
            'transaction_id' => ($payments[0] ?? null)?->optionalString('externalTransactionId'),
            'fees' => [],
            'refunds' => self::refunds($payments),
            'notes' => self::notes($node, $orderId),
            'order' => $node->only(self::DETAILS),
        ]);
    }

    /** Squarespace's fulfillment status and payment state → a WooCommerce order status. */
    public static function status(string $fulfillment, ?string $payment): string
    {
        return match (true) {
            $fulfillment === 'CANCELED' => 'cancelled',
            $payment === 'REFUNDED' => 'refunded',
            $payment === 'FAILED' => 'failed',
            $fulfillment === 'FULFILLED' => 'completed',
            $payment === 'PENDING', $payment === 'NOT_CHARGED' => 'pending',
            $payment === 'AUTHORIZED' => 'on-hold',
            default => 'processing',
        };
    }

    /** @return list<string> the distinct promo codes redeemed */
    private static function coupons(Node $order): array
    {
        $codes = array_map(static fn(Node $line): ?string => $line->optionalString('promoCode'), $order->list('discountLines'));

        return array_values(array_unique(array_filter($codes, static fn(?string $code): bool => $code !== null)));
    }

    /** @return array<string, mixed> */
    private static function lineItem(Node $line): array
    {
        $productId = $line->optionalString('productId');
        $quantity = $line->int('quantity');
        $subtotal = Decimal::multiply(Fields::moneyOrZero($line, 'unitPricePaid'), $quantity);
        $attributes = [];
        foreach ([...$line->list('variantOptions'), ...$line->list('customizations')] as $choice) {
            $label = $choice->optionalString('optionName') ?? $choice->optionalString('label');
            $value = $choice->optionalString('value');
            if ($label !== null && $value !== null) {
                $attributes[$label] = $value;
            }
        }

        return [
            'gid' => Fields::id('OrderLineItem', $line->string('id')),
            'name' => $line->optionalString('productName') ?? '',
            'gift_card' => $line->optionalString('lineItemType') === self::GIFT_CARD,
            'product_gid' => $productId === null ? null : Fields::productId($productId),
            'variant_gid' => $productId === null ? null : Fields::variantId($productId, $line->optionalString('variantId')),
            'sku' => $line->optionalString('sku'),
            'quantity' => $quantity,
            'taxable' => true,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'taxes' => [],
            'attributes' => $attributes,
        ];
    }

    /**
     * The shipping lines; an order with a shipping total but no lines gets one for its shipping option.
     *
     * @return list<array<string, mixed>>
     */
    private static function shippingLines(Node $order): array
    {
        $option = $order->optionalString('shippingOptionName');
        $lines = array_map(static fn(Node $line): array => [
            'title' => $line->optionalString('method') ?? $option ?? 'Shipping',
            'code' => null,
            'source' => self::SOURCE,
            'total' => Fields::moneyOrZero($line, 'amount'),
            'taxes' => [],
        ], $order->list('shippingLines'));
        $total = Fields::moneyOrZero($order, 'shippingTotal');
        if ($lines === [] && !Decimal::isZero($total)) {
            $lines[] = ['title' => $option ?? 'Shipping', 'code' => null, 'source' => self::SOURCE, 'total' => $total, 'taxes' => []];
        }

        return $lines;
    }

    /**
     * One order-level tax line per tax name in the order's transactions (summed over its sale and shipping
     * items, with its rate when every item names the same one); one "Tax" line for an order whose tax is
     * known only as its total.
     *
     * @param list<Node> $documents
     *
     * @return list<array{title: string, rate: ?string, order_amount: string}>
     */
    private static function taxLines(Node $order, array $documents): array
    {
        $amounts = [];
        $rates = [];
        foreach ($documents as $document) {
            foreach ([...$document->list('salesLineItems'), ...$document->list('shippingLineItems')] as $item) {
                foreach ($item->list('taxes') as $tax) {
                    $title = $tax->optionalString('name') ?? 'Tax';
                    $amounts[$title][] = Fields::moneyOrZero($tax, 'amount');
                    $rates[$title][] = $tax->optionalDecimal('rate');
                }
            }
        }
        $lines = [];
        foreach ($amounts as $title => $values) {
            $distinct = array_values(array_unique(array_map(static fn(?string $rate): string => $rate ?? '', $rates[$title])));
            $rate = count($distinct) === 1 && $distinct[0] !== '' ? Decimal::shift($distinct[0], -self::PERCENT_PLACES) : null;
            $lines[] = ['title' => (string) $title, 'rate' => $rate, 'order_amount' => Decimal::sum($values)];
        }
        $total = Fields::moneyOrZero($order, 'taxTotal');
        if ($lines === [] && !Decimal::isZero($total)) {
            return [['title' => 'Tax', 'rate' => null, 'order_amount' => $total]];
        }

        return $lines;
    }

    /** @param list<Node> $payments */
    private static function paidAt(array $payments): ?string
    {
        $times = array_values(array_filter(array_map(static fn(Node $payment): ?string => Fields::time($payment, 'paidOn'), $payments)));
        sort($times);

        return $times[0] ?? null;
    }

    /**
     * The first payment's gateway and card ("STRIPE (VISA)").
     *
     * @param list<Node> $payments
     */
    private static function paymentMethod(array $payments): ?string
    {
        $payment = $payments[0] ?? null;
        $provider = $payment?->optionalString('provider');
        if ($payment === null || $provider === null) {
            return null;
        }
        $card = $payment->optionalString('creditCardType');

        return $card === null ? $provider : "{$provider} ({$card})";
    }

    /**
     * Every refund of every payment, for its amount (Squarespace doesn't say which lines a refund was for).
     *
     * @param list<Node> $payments
     *
     * @return list<array<string, mixed>>
     */
    private static function refunds(array $payments): array
    {
        $refunds = [];
        foreach ($payments as $payment) {
            foreach ($payment->list('refunds') as $refund) {
                $refunds[] = [
                    'gid' => Fields::id('Refund', $refund->string('id')),
                    'created_at' => Fields::time($refund, 'refundedOn'),
                    'note' => null,
                    'amount' => Fields::moneyOrZero($refund, 'amount'),
                    'lines' => [],
                ];
            }
        }

        return $refunds;
    }

    /**
     * Each fulfillment (carrier, service, tracking) and internal note once. Neither has an id, so each is
     * identified by the order and its position (Squarespace only ever adds them).
     *
     * @return list<array{gid: string, created_at: string, author: ?string, message: string}>
     */
    private static function notes(Node $order, string $orderId): array
    {
        $created = Fields::requiredTime($order, 'createdOn');
        $notes = [];
        foreach ($order->list('fulfillments') as $index => $fulfillment) {
            $parts = array_filter([
                $fulfillment->optionalString('carrierName'),
                $fulfillment->optionalString('service'),
                $fulfillment->optionalString('trackingNumber'),
                $fulfillment->optionalString('trackingUrl'),
            ], static fn(?string $part): bool => $part !== null);
            $notes[] = [
                'gid' => Fields::id('Fulfillment', "{$orderId}-{$index}"),
                'created_at' => Fields::time($fulfillment, 'shipDate') ?? $created,
                'author' => null,
                'message' => trim('Squarespace fulfillment: ' . implode(' ', $parts)),
            ];
        }
        foreach ($order->list('internalNotes') as $index => $note) {
            $content = $note->optionalString('content');
            if ($content !== null) {
                $notes[] = ['gid' => Fields::id('InternalNote', "{$orderId}-{$index}"), 'created_at' => $created, 'author' => 'Staff', 'message' => $content];
            }
        }

        return $notes;
    }
}
