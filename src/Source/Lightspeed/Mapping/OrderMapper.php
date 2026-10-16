<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * An order → a WooCommerce order with Lightspeed's totals, in the shop's currency (Lightspeed orders don't
 * name one). Lines point at the product and its variant (see Fields::variantId), with their discounts and
 * taxes; the shipping method is a shipping line, payment costs a fee, the coupon code a coupon line, each
 * credit note a WooCommerce refund (with the lines it credits), and each shipment (number, state, tracking
 * code) and the staff memo order notes. Lightspeed's order status maps to a WooCommerce status (see
 * status()). The payment and shipment states and data, channel, custom status, company, language, delivery
 * and shipping dates are mirrored as meta. Lightspeed keeps no payment transaction id.
 */
final class OrderMapper implements Mapper
{
    public const SOURCE = 'lightspeed';

    private const VERSION = 1;
    /** Lightspeed order status → WooCommerce status (developers.lightspeedhq.com/ecom/endpoints/order/). */
    private const STATUS = [
        'on_hold' => 'on-hold',
        'processing_awaiting_payment' => 'pending',
        'processing_awaiting_shipment' => 'processing',
        'processing_awaiting_pickup' => 'processing',
        'processing_ready_for_pickup' => 'processing',
        'completed' => 'completed',
        'completed_shipped' => 'completed',
        'completed_picked_up' => 'completed',
        'cancelled' => 'cancelled',
    ];
    private const PAID = ['paid', 'partially_paid'];
    private const SHIPPED = 'shipped';
    private const CREDITED_PRODUCT = 'product';
    private const VARIANT_ATTRIBUTE = 'Variant';
    /** The title Lightspeed gives a product's one variant when it has no options (it says nothing about the line). */
    private const DEFAULT_VARIANT_TITLE = 'Default';
    private const DETAILS = [
        'status', 'paymentStatus', 'shipmentStatus', 'customStatusId', 'channel', 'paymentId', 'paymentData',
        'paymentIsPost', 'paymentIsInvoiceExternal', 'shipmentId', 'shipmentData', 'shipmentIsCashOnDelivery',
        'shipmentIsPickup', 'shippingDate', 'deliveryDate', 'isDiscounted', 'discountType', 'discountAmount',
        'discountPercentage', 'isNewCustomer', 'language', 'middlename', 'mobile', 'gender', 'birthDate', 'nationalId',
        'isCompany', 'companyName', 'companyCoCNumber', 'companyVatNumber', 'newsletterSubscribed', 'weight',
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
        $first = Fields::text($node, 'firstname');
        $last = Fields::text($node, 'lastname');
        $phone = Fields::text($node, 'phone') ?? Fields::text($node, 'mobile');
        $email = Fields::text($node, 'email');
        $customerId = Fields::linkedId($node, 'customer');
        $lines = $node->list('products');
        $coupon = Fields::text($node, 'discountCouponCode');
        $total = Fields::decimalOrZero($node, 'priceIncl');

        return new Desired(Kind::Order, $node->id(), [
            'name' => $node->string('number'),
            'created_at' => Fields::requiredTime($node, 'createdAt'),
            'currency' => $node->string('currency'),
            'prices_include_tax' => Fields::pricesIncludeTax($node),
            'customer_gid' => $customerId === null ? null : Fields::customerId($customerId),
            'email' => $email === null ? null : strtolower($email),
            'phone' => $phone,
            'billing' => Fields::address($node, 'addressBilling', $first, $last, $phone),
            'shipping' => Fields::address($node, 'addressShipping', $first, $last),
            'tags' => [],
            'coupons' => $coupon === null ? [] : [$coupon],
            'line_items' => array_map(self::lineItem(...), $lines),
            'shipping_lines' => self::shippingLines($node),
            'tax_lines' => self::taxLines($node),
            'discount_total' => Decimal::sum(array_map(static fn(Node $line): string => Fields::decimalOrZero($line, 'discountExcl'), $lines)),
            'shipping_total' => Fields::decimalOrZero($node, 'shipmentPriceExcl'),
            'tax_total' => Decimal::subtract($total, Fields::decimalOrZero($node, 'priceExcl')),
            'total' => $total,
            'status' => self::status($node->string('status')),
            'processed_at' => in_array(Fields::text($node, 'paymentStatus'), self::PAID, true) ? Fields::time($node, 'createdAt') : null,
            'closed_at' => self::shippedAt($node),
            'customer_note' => Fields::text($node, 'comment'),
            'source_name' => self::SOURCE,
            'payment_method_title' => Fields::text($node, 'paymentTitle'),
            'transaction_id' => null,
            'fees' => self::fees($node),
            'refunds' => array_map(self::refund(...), $node->list('credit_notes')),
            'notes' => self::notes($node),
            'order' => Fields::details($node, self::DETAILS),
        ]);
    }

    /** A Lightspeed order status → a WooCommerce order status (an unknown one is pending). */
    public static function status(string $status): string
    {
        return self::STATUS[$status] ?? 'pending';
    }

    /** @return array<string, mixed> */
    private static function lineItem(Node $line): array
    {
        $quantity = $line->int('quantityOrdered');
        $productId = Fields::linkedId($line, 'product');
        $variantId = Fields::linkedId($line, 'variant');
        $taxes = Fields::taxes($line, 'taxRates');
        $productTitle = Fields::text($line, 'productTitle') ?? '';
        $variantTitle = Fields::text($line, 'variantTitle');

        return [
            'gid' => self::lineId($line->int('id')),
            'name' => $productTitle,
            'gift_card' => false,
            'product_gid' => $productId === null ? null : Fields::productId($productId),
            'variant_gid' => $productId === null || $variantId === null ? null : Fields::variantId($variantId),
            'sku' => Fields::text($line, 'sku'),
            'quantity' => $quantity,
            'taxable' => $taxes !== [],
            'subtotal' => Decimal::multiply(Fields::decimalOrZero($line, 'basePriceExcl'), $quantity),
            'total' => Fields::decimalOrZero($line, 'priceExcl'),
            'taxes' => $taxes,
            'attributes' => in_array($variantTitle, [null, $productTitle, self::DEFAULT_VARIANT_TITLE], true) ? [] : [self::VARIANT_ATTRIBUTE => $variantTitle],
        ];
    }

    private static function lineId(int $orderProductId): string
    {
        return Fields::id('OrderProduct', $orderProductId);
    }

    /** @return list<array<string, mixed>> the order's shipping method, when it has one */
    private static function shippingLines(Node $order): array
    {
        $title = Fields::text($order, 'shipmentTitle');
        $cost = Fields::decimalOrZero($order, 'shipmentPriceExcl');
        if ($title === null && Decimal::isZero($cost)) {
            return [];
        }

        return [[
            'title' => $title ?? 'Shipping',
            'code' => Fields::text($order, 'shipmentId'),
            'source' => self::SOURCE,
            'total' => $cost,
            'taxes' => Fields::taxes($order, 'shipmentTaxRates'),
        ]];
    }

    /** @return list<array<string, mixed>> the payment method's costs, when it has any */
    private static function fees(Node $order): array
    {
        $cost = Fields::decimalOrZero($order, 'paymentPriceExcl');

        return Decimal::isZero($cost) ? [] : [[
            'name' => Fields::text($order, 'paymentTitle') ?? 'Payment costs',
            'total' => $cost,
            'taxes' => Fields::taxes($order, 'paymentTaxRates'),
        ]];
    }

    /**
     * One tax line per tax the order charged; an order whose tax Lightspeed reports only as its total gets
     * one order-level line.
     *
     * @return list<array{title: string, rate: ?string, order_amount: ?string}>
     */
    private static function taxLines(Node $order): array
    {
        $lines = [];
        foreach (Fields::taxes($order, 'taxRates') as $tax) {
            $lines[$tax['title']] ??= ['title' => $tax['title'], 'rate' => $tax['rate'], 'order_amount' => null];
        }
        $total = Decimal::subtract(Fields::decimalOrZero($order, 'priceIncl'), Fields::decimalOrZero($order, 'priceExcl'));
        if ($lines === [] && !Decimal::isZero($total)) {
            return [['title' => 'Tax', 'rate' => null, 'order_amount' => $total]];
        }

        return array_values($lines);
    }

    /** When the last shipped shipment was shipped (its last update); null until one has shipped. */
    private static function shippedAt(Node $order): ?string
    {
        $times = [];
        foreach ($order->list('shipments') as $shipment) {
            if (Fields::text($shipment, 'status') === self::SHIPPED) {
                $times[] = Fields::time($shipment, 'updatedAt');
            }
        }
        $times = array_filter($times, static fn(?string $time): bool => $time !== null);

        return $times === [] ? null : max($times);
    }

    /**
     * A credit note (an invoice crediting the order, with negative amounts) as a refund of what it credits.
     *
     * @return array<string, mixed>
     */
    private static function refund(Node $creditNote): array
    {
        $lines = [];
        foreach ($creditNote->list('items') as $item) {
            $lineId = $item->optionalInt('orderProductId');
            $quantity = abs($item->optionalInt('quantity') ?? 0);
            if (Fields::text($item, 'type') === self::CREDITED_PRODUCT && $lineId !== null && $quantity > 0) {
                $lines[] = [
                    'line_item_gid' => self::lineId($lineId),
                    'quantity' => $quantity,
                    'subtotal' => Fields::magnitude(Fields::decimalOrZero($item, 'priceExcl')),
                ];
            }
        }

        return [
            'gid' => Fields::id('CreditNote', $creditNote->int('id')),
            'created_at' => Fields::time($creditNote, 'createdAt'),
            'note' => 'Lightspeed credit note ' . ($creditNote->optionalString('number') ?? $creditNote->int('id')),
            'amount' => Fields::magnitude(Fields::decimalOrZero($creditNote, 'priceIncl')),
            'lines' => $lines,
        ];
    }

    /**
     * Each shipment (number, state, tracking code) once, and the staff memo.
     *
     * @return list<array{gid: string, created_at: string, author: ?string, message: string}>
     */
    private static function notes(Node $order): array
    {
        $notes = [];
        foreach ($order->list('shipments') as $shipment) {
            $tracking = Fields::text($shipment, 'trackingCode');
            $notes[] = [
                'gid' => Fields::id('Shipment', $shipment->int('id')),
                'created_at' => Fields::time($shipment, 'createdAt') ?? Fields::requiredTime($order, 'createdAt'),
                'author' => null,
                'message' => trim('Lightspeed shipment ' . implode(' ', array_filter([
                    Fields::text($shipment, 'number'),
                    '(' . str_replace('_', ' ', Fields::text($shipment, 'status') ?? 'unknown') . ')',
                    $tracking === null ? null : "tracking {$tracking}",
                ], static fn(?string $part): bool => $part !== null))),
            ];
        }
        $memo = Fields::text($order, 'memo');
        if ($memo !== null) {
            $notes[] = ['gid' => Fields::id('Memo', Fields::sourceId($order)), 'created_at' => Fields::requiredTime($order, 'createdAt'), 'author' => 'Staff', 'message' => $memo];
        }

        return $notes;
    }
}
