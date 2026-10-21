<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/**
 * An order → a WooCommerce order with Webflow's totals (amounts are in the currency's minor unit). Status:
 * `pending` → pending, `unfulfilled` → processing, `fulfilled` → completed, `disputed` → on hold, and
 * `refunded` or `dispute-lost` → refunded with one refund of what the customer paid (Webflow refunds only whole
 * orders), dated when it was refunded or the dispute last changed. Items are linked to their product and SKU;
 * each item's total is its row total. The order's extras are its shipping line (`shipping`, less any
 * `discount-shipping`), its discount (`discount`) and its tax, which Webflow reports only for the whole order.
 * The customer is the buyer gathered by email (see CustomerMapper). The customer's comment, the tracking
 * number and provider are notes and fields; payment processor, Stripe and PayPal ids, the card's brand and last
 * digits, the net amount and fee, custom checkout data and download files are meta.
 */
final class OrderMapper implements Mapper
{
    private const VERSION = 1;
    private const STATUSES = [
        'pending' => 'pending',
        'unfulfilled' => 'processing',
        'fulfilled' => 'completed',
        'disputed' => 'on-hold',
        'refunded' => 'refunded',
        'dispute-lost' => 'refunded',
    ];
    private const REFUNDED = ['refunded', 'dispute-lost'];
    private const PAID = ['unfulfilled', 'fulfilled', 'disputed', 'refunded', 'dispute-lost'];
    private const DETAILS = [
        'comment', 'paymentProcessor', 'stripeDetails', 'stripeCard', 'paypalDetails', 'netAmount', 'applicationFee',
        'customData', 'downloadFiles', 'disputeLastStatus', 'disputedOn', 'shippingProvider', 'shippingTracking',
        'shippingTrackingURL', 'isShippingRequired', 'metadata', 'isCustomerDeleted',
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
        $orderId = Fields::sourceId($node);
        $status = $node->string('status');
        $totals = $node->requiredChild('totals');
        $currency = strtoupper($totals->requiredChild('total')->string('unit'));
        $customer = $node->child('customerInfo');
        $email = $customer?->optionalString('email');
        $extras = self::extras($totals);
        $shipping = Decimal::subtract($extras['shipping'], $extras['discount-shipping']);
        $tax = $extras['tax'];

        return new Desired(Kind::Order, $node->id(), [
            'name' => $orderId,
            'created_at' => Fields::time($node, 'acceptedOn') ?? throw new SourceError("Order {$orderId} has no acceptedOn."),
            'currency' => $currency,
            'prices_include_tax' => false,
            'customer_gid' => $email === null ? null : Fields::customerId($email),
            'email' => $email === null ? null : strtolower($email),
            'phone' => null,
            'billing' => Fields::address($node->child('billingAddress')),
            'shipping' => Fields::address($node->child('shippingAddress')),
            'coupons' => [],
            'line_items' => array_map(static fn(Node $item): array => self::lineItem($item, $orderId), $node->list('purchasedItems')),
            'shipping_lines' => $extras['shipping_title'] === null ? [] : [[
                'title' => $extras['shipping_title'],
                'code' => null,
                'total' => $shipping,
                'taxes' => [],
            ]],
            'tax_lines' => Decimal::isZero($tax) ? [] : [['title' => $extras['tax_title'] ?? 'Tax', 'rate' => null, 'order_amount' => $tax]],
            'discount_total' => $extras['discount'],
            'shipping_total' => $shipping,
            'tax_total' => $tax,
            'total' => Fields::money($totals->requiredChild('total')),
            'status' => self::STATUSES[$status] ?? 'on-hold',
            'processed_at' => in_array($status, self::PAID, true) ? Fields::time($node, 'acceptedOn') : null,
            'closed_at' => Fields::time($node, 'fulfilledOn'),
            'customer_note' => $node->optionalString('orderComment'),
            'payment_method_title' => $node->optionalString('paymentProcessor'),
            'transaction_id' => $node->child('stripeDetails')?->optionalString('chargeId') ?? $node->child('paypalDetails')?->optionalString('captureId'),
            'fees' => [],
            'refunds' => in_array($status, self::REFUNDED, true) ? [[
                'gid' => Fields::id('Refund', $orderId),
                'amount' => Fields::money($node->child('customerPaid') ?? $totals->requiredChild('total')),
                'note' => $node->child('stripeDetails')?->optionalString('refundReason') ?? $node->child('paypalDetails')?->optionalString('refundReason'),
                'created_at' => Fields::time($node, 'refundedOn') ?? Fields::time($node, 'disputeUpdatedOn'),
                'lines' => [],
            ]] : [],
            'notes' => self::notes($node, $orderId),
            'original_meta' => [],
            'order' => $node->only(self::DETAILS),
        ]);
    }

    /** @return array<string, mixed> */
    private static function lineItem(Node $item, string $orderId): array
    {
        $productId = $item->optionalString('productId');
        $variantId = $item->optionalString('variantId');
        $total = Fields::money($item->child('rowTotal'));
        $attributes = [];
        $variantName = $item->optionalString('variantName');
        if ($variantName !== null && $variantName !== $item->optionalString('productName')) {
            $attributes['Variant'] = $variantName;
        }

        return [
            'gid' => Fields::id('OrderItem', "{$orderId}-" . ($variantId ?? $productId ?? $item->string('productName'))),
            'name' => $item->optionalString('productName') ?? $variantName ?? '',
            'product_gid' => $productId === null ? null : Fields::productId($productId),
            'variant_gid' => $variantId === null ? null : Fields::variantId($variantId),
            'sku' => $item->optionalString('variantSKU'),
            'quantity' => $item->int('count'),
            'subtotal' => $total,
            'total' => $total,
            'taxes' => [],
            'attributes' => $attributes,
        ];
    }

    /**
     * The order's extras by type: shipping, its discount, the discount and the tax, each summed as a positive
     * amount, with the shipping and tax names.
     *
     * @return array{shipping: string, discount-shipping: string, discount: string, tax: string, shipping_title: ?string, tax_title: ?string}
     */
    private static function extras(Node $totals): array
    {
        $sums = ['shipping' => [], 'discount-shipping' => [], 'discount' => [], 'tax' => []];
        $titles = ['shipping' => null, 'tax' => null];
        foreach ($totals->list('extras') as $extra) {
            $type = $extra->string('type');
            if (!isset($sums[$type])) {
                continue;
            }
            $amount = Fields::money($extra->child('price'));
            $sums[$type][] = str_starts_with($amount, '-') ? substr($amount, 1) : $amount;
            if (array_key_exists($type, $titles)) {
                $titles[$type] ??= $extra->optionalString('name');
            }
        }

        return [
            'shipping' => Decimal::sum($sums['shipping']),
            'discount-shipping' => Decimal::sum($sums['discount-shipping']),
            'discount' => Decimal::sum($sums['discount']),
            'tax' => Decimal::sum($sums['tax']),
            'shipping_title' => $titles['shipping'],
            'tax_title' => $titles['tax'],
        ];
    }

    /** @return list<array<string, ?string>> the tracking number, as a note once the order is fulfilled */
    private static function notes(Node $order, string $orderId): array
    {
        $tracking = $order->optionalString('shippingTracking');
        $fulfilled = Fields::time($order, 'fulfilledOn');
        if ($tracking === null || $fulfilled === null) {
            return [];
        }
        $provider = $order->optionalString('shippingProvider');

        return [[
            'gid' => Fields::id('Tracking', $orderId),
            'author' => null,
            'message' => trim(($provider === null ? '' : "{$provider} ") . "tracking {$tracking}" . ($order->optionalString('shippingTrackingURL') === null ? '' : ' ' . $order->optionalString('shippingTrackingURL'))),
            'created_at' => $fulfilled,
        ]];
    }
}
