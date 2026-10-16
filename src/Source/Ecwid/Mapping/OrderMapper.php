<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Ecwid\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * An order → a WooCommerce order with Ecwid's totals, in the store's currency (orders don't name one), its
 * id (with the store's prefix and suffix) as the order number. Items point at the product and its variation
 * (see Fields::variantId), with their coupon and promotion discounts, taxes and the options the customer
 * chose; the shipping option is a shipping line (with the taxes on shipping), the handling fee and custom
 * surcharges are fees, the coupon code a coupon line, each refund a WooCommerce refund, and the tracking
 * number, each purchased shipping label and the store owner's private notes are order notes. The payment and
 * fulfillment statuses map to a WooCommerce status (see status()). The statuses themselves, the internal id,
 * promotions, coupon details, customer group and tax details, pickup time, checkout extra fields, invoices,
 * referrers and the subtotals and totals Ecwid reports are mirrored as meta.
 */
final class OrderMapper implements Mapper
{
    public const SOURCE = 'ecwid';

    private const VERSION = 1;
    /** Payment statuses whose order was paid (Ecwid keeps no payment time; the order's time stands in). */
    private const PAID = ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'];
    /** Fulfillment statuses of an order that reached its customer (or came back). */
    private const FULFILLED = ['SHIPPED', 'DELIVERED', 'RETURNED'];
    private const NOT_DELIVERED = 'WILL_NOT_DELIVER';
    private const DETAILS = [
        'internalId', 'paymentStatus', 'fulfillmentStatus', 'paymentModule', 'subtotal', 'subtotalWithoutTax', 'totalWithoutTax',
        'refundedAmount', 'giftCardRedemption', 'totalBeforeGiftCardRedemption', 'couponDiscount', 'volumeDiscount',
        'membershipBasedDiscount', 'totalAndMembershipBasedDiscount', 'customDiscount', 'discountInfo', 'discountCoupon',
        'customerGroup', 'customerGroupId', 'customerTaxExempt', 'customerTaxId', 'customerTaxIdValid', 'reversedTaxApplied',
        'b2b_b2c', 'pickupTime', 'extraFields', 'orderExtraFields', 'invoices', 'affiliateId', 'externalOrderId',
        'externalFulfillment', 'refererUrl', 'globalReferer', 'refererId', 'lang', 'acceptMarketing', 'hidden', 'utmData',
        'shippingOption',
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
        $email = $node->optionalString('email');
        $customerId = Fields::linkedId($node, 'customerId');
        $billing = Fields::address($node->child('billingPerson'));
        $coupon = $node->child('discountCoupon')?->optionalString('code');
        $grossPrices = ($node->raw()['pricesIncludeTax'] ?? false) === true;
        $paymentStatus = $node->string('paymentStatus');
        $orderId = Fields::sourceId($node);

        return new Desired(Kind::Order, $node->id(), [
            'name' => $orderId,
            'created_at' => Fields::requiredTime($node, 'createDate'),
            'currency' => $node->string('currency'),
            'prices_include_tax' => $grossPrices,
            'customer_gid' => $customerId === null ? null : Fields::customerId($customerId),
            'email' => $email === null ? null : strtolower($email),
            'phone' => $billing['phone'] ?? null,
            'billing' => $billing,
            'shipping' => Fields::address($node->child('shippingPerson')),
            'tags' => [],
            'coupons' => $coupon === null ? [] : [$coupon],
            'line_items' => array_map(static fn(Node $item): array => self::lineItem($item, $grossPrices), $node->list('items')),
            'shipping_lines' => self::shippingLines($node),
            'tax_lines' => self::taxLines($node),
            'discount_total' => Fields::decimalOrZero($node, 'discount'),
            'shipping_total' => self::shippingCost($node),
            'tax_total' => Fields::decimalOrZero($node, 'tax'),
            'total' => Fields::decimalOrZero($node, 'total'),
            'status' => self::status($paymentStatus, $node->optionalString('fulfillmentStatus')),
            'processed_at' => in_array($paymentStatus, self::PAID, true) ? Fields::time($node, 'createDate') : null,
            'closed_at' => self::shippedAt($node),
            'customer_note' => $node->optionalString('orderComments'),
            'source_name' => self::SOURCE,
            'payment_method_title' => $node->optionalString('paymentMethod'),
            'transaction_id' => $node->optionalString('externalTransactionId'),
            'fees' => self::fees($node),
            'refunds' => self::refunds($node, $orderId),
            'notes' => self::notes($node, $orderId),
            'order' => $node->only(self::DETAILS),
        ]);
    }

    /**
     * Ecwid's payment and fulfillment statuses → a WooCommerce order status. Awaiting payment is pending and an
     * unfinished online payment (incomplete) failed; cancelled and refunded orders are cancelled and refunded;
     * a paid (or partly refunded) order is completed once shipped, delivered or returned, cancelled when it
     * will not be delivered, and processing otherwise; a store's custom payment status is on hold.
     */
    public static function status(string $payment, ?string $fulfillment): string
    {
        return match ($payment) {
            'AWAITING_PAYMENT' => 'pending',
            'INCOMPLETE' => 'failed',
            'CANCELLED' => 'cancelled',
            'REFUNDED' => 'refunded',
            'PAID', 'PARTIALLY_REFUNDED' => match (true) {
                in_array($fulfillment, self::FULFILLED, true) => 'completed',
                $fulfillment === self::NOT_DELIVERED => 'cancelled',
                default => 'processing',
            },
            default => 'on-hold',
        };
    }

    /**
     * An item: its subtotal without tax before discounts, and its total without tax after its coupon amount
     * and promotions. With gross prices (tax included) the total is the discounted price less the tax Ecwid
     * charged on it; with net prices it is the discounted price.
     *
     * @return array<string, mixed>
     */
    private static function lineItem(Node $item, bool $grossPrices): array
    {
        $quantity = $item->int('quantity');
        $productId = Fields::linkedId($item, 'productId');
        $taxes = Fields::taxes($item, 'taxes', 'taxOnDiscountedSubtotal');
        $price = Decimal::multiply($item->decimal('price'), $quantity);
        $discounted = Decimal::subtract(Decimal::subtract($price, Fields::decimalOrZero($item, 'couponAmount')), self::promotions($item));
        $attributes = [];
        foreach (is_array($item->raw()['selectedOptions'] ?? null) ? $item->list('selectedOptions') : [] as $option) {
            $value = $option->optionalString('value');
            if ($value !== null) {
                $attributes[$option->string('name')] = $value;
            }
        }

        return [
            'gid' => Fields::id('OrderItem', $item->int('id')),
            'name' => $item->optionalString('name') ?? '',
            'gift_card' => false,
            'product_gid' => $productId === null ? null : Fields::productId($productId),
            'variant_gid' => $productId === null ? null : Fields::variantId($productId, Fields::linkedId($item, 'combinationId')),
            'sku' => $item->optionalString('sku'),
            'quantity' => $quantity,
            'taxable' => $taxes !== [],
            'subtotal' => Decimal::multiply($item->optionalDecimal('priceWithoutTax') ?? $item->decimal('price'), $quantity),
            'total' => $grossPrices ? Decimal::subtract($discounted, Decimal::sum(array_column($taxes, 'amount'))) : $discounted,
            'taxes' => $taxes,
            'attributes' => $attributes,
        ];
    }

    /** The promotions taken off an item (each discount's total). */
    private static function promotions(Node $item): string
    {
        $totals = [];
        foreach (is_array($item->raw()['discounts'] ?? null) ? $item->list('discounts') : [] as $discount) {
            $totals[] = Fields::decimalOrZero($discount, 'total');
        }

        return Decimal::sum($totals);
    }

    /** The shipping cost without tax. */
    private static function shippingCost(Node $order): string
    {
        $option = $order->child('shippingOption');

        return $option?->optionalDecimal('shippingRateWithoutTax') ?? Fields::decimalOrZero($option, 'shippingRate');
    }

    /** @return list<array<string, mixed>> the order's shipping option, when it has one */
    private static function shippingLines(Node $order): array
    {
        $option = $order->child('shippingOption');
        if ($option === null) {
            return [];
        }

        return [[
            'title' => $option->optionalString('shippingMethodName') ?? 'Shipping',
            'code' => $option->optionalString('shippingMethodId'),
            'source' => self::SOURCE,
            'total' => self::shippingCost($order),
            'taxes' => Fields::taxes($order, 'taxesOnShipping'),
        ]];
    }

    /** @return list<array<string, mixed>> the handling fee and the custom surcharges */
    private static function fees(Node $order): array
    {
        $fees = [];
        $handling = $order->child('handlingFee');
        $handlingCost = Fields::setAmount($handling, 'value');
        if ($handling !== null && $handlingCost !== null) {
            $fees[] = ['name' => $handling->optionalString('name') ?? 'Handling fee', 'total' => $handlingCost, 'taxes' => []];
        }
        foreach (is_array($order->raw()['customSurcharges'] ?? null) ? $order->list('customSurcharges') : [] as $surcharge) {
            $fees[] = [
                'name' => $surcharge->optionalString('description') ?? $surcharge->optionalString('id') ?? 'Surcharge',
                'total' => $surcharge->optionalDecimal('totalWithoutTax') ?? Fields::decimalOrZero($surcharge, 'total'),
                'taxes' => Fields::taxes($surcharge, 'taxes'),
            ];
        }

        return $fees;
    }

    /**
     * One tax line per tax the order charged (on items, shipping or surcharges); an order whose tax Ecwid
     * reports only as its total gets one order-level line.
     *
     * @return list<array{title: string, rate: ?string, order_amount: ?string}>
     */
    private static function taxLines(Node $order): array
    {
        $taxes = Fields::taxes($order, 'taxesOnShipping');
        foreach ($order->list('items') as $item) {
            $taxes = [...$taxes, ...Fields::taxes($item, 'taxes', 'taxOnDiscountedSubtotal')];
        }
        foreach (is_array($order->raw()['customSurcharges'] ?? null) ? $order->list('customSurcharges') : [] as $surcharge) {
            $taxes = [...$taxes, ...Fields::taxes($surcharge, 'taxes')];
        }
        $lines = [];
        foreach ($taxes as $tax) {
            $lines[$tax['title']] ??= ['title' => $tax['title'], 'rate' => $tax['rate'], 'order_amount' => null];
        }
        $total = Fields::decimalOrZero($order, 'tax');
        if ($lines === [] && !Decimal::isZero($total)) {
            return [['title' => 'Tax', 'rate' => null, 'order_amount' => $total]];
        }

        return array_values($lines);
    }

    /** When the last shipping label was bought, for a fulfilled order; null otherwise. */
    private static function shippedAt(Node $order): ?string
    {
        if (!in_array($order->optionalString('fulfillmentStatus'), self::FULFILLED, true)) {
            return null;
        }
        $times = array_filter(
            array_map(static fn(Node $shipment): ?string => Fields::time($shipment, 'created'), $order->list('shipments')),
            static fn(?string $time): bool => $time !== null,
        );

        return $times === [] ? null : max($times);
    }

    /**
     * Ecwid's refunds as WooCommerce refunds. They have no ids and are only ever added, so each is identified
     * by its place in the order's list.
     *
     * @return list<array<string, mixed>>
     */
    private static function refunds(Node $order, string $orderId): array
    {
        $refunds = [];
        foreach ($order->list('refunds') as $index => $refund) {
            $refunds[] = [
                'gid' => Fields::id('Refund', "{$orderId}-" . ($index + 1)),
                'created_at' => Fields::time($refund, 'date'),
                'note' => $refund->optionalString('reason') ?? 'Ecwid refund',
                'amount' => Fields::decimalOrZero($refund, 'amount'),
                'lines' => [],
            ];
        }

        return $refunds;
    }

    /**
     * The tracking number, each purchased shipping label (carrier, tracking number) once, and the store
     * owner's private notes.
     *
     * @return list<array{gid: string, created_at: string, author: ?string, message: string}>
     */
    private static function notes(Node $order, string $orderId): array
    {
        $placed = Fields::requiredTime($order, 'createDate');
        $notes = [];
        $tracking = $order->optionalString('trackingNumber');
        if ($tracking !== null) {
            $notes[] = ['gid' => Fields::id('Tracking', "{$orderId}-{$tracking}"), 'created_at' => $placed, 'author' => null, 'message' => "Ecwid tracking number {$tracking}"];
        }
        foreach ($order->list('shipments') as $shipment) {
            $id = $shipment->string('id');
            $number = $shipment->child('tracking')?->optionalString('tracking_number');
            $notes[] = [
                'gid' => Fields::id('Shipment', "{$orderId}-{$id}"),
                'created_at' => Fields::time($shipment, 'created') ?? $placed,
                'author' => null,
                'message' => trim('Ecwid shipping label ' . implode(' ', array_filter([
                    $shipment->child('shippingService')?->optionalString('carrierName'),
                    $number === null ? null : "tracking {$number}",
                ], static fn(?string $part): bool => $part !== null))),
            ];
        }
        $memo = $order->optionalString('privateAdminNotes');
        if ($memo !== null) {
            $notes[] = ['gid' => Fields::id('Memo', $orderId), 'created_at' => $placed, 'author' => 'Staff', 'message' => $memo];
        }

        return $notes;
    }
}
