<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Magento\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * An order → a WooCommerce order with Magento's totals (the order currency's amounts). A configurable product's
 * line (a parent line and the child line of the product bought) becomes one line pointing at the product and
 * its variant, the child product (see Fields::variantId); other lines point at their product and its one
 * variant, except bundle, grouped and gift card lines, which have no mirrored product. Line and shipping taxes
 * come from Magento's applied taxes; the shipping method is a shipping line, the coupon codes coupon lines,
 * credit memos WooCommerce refunds, and each shipment (carriers and tracking numbers) and each order comment
 * an order note. Magento's order state maps to a WooCommerce status (see status()). The store, customer group,
 * base-currency totals, payment details, applied rules and remote IP are mirrored as meta.
 */
final class OrderMapper implements Mapper
{
    public const SOURCE = 'magento';

    private const VERSION = 1;
    /** Magento order state → WooCommerce status. */
    private const STATES = [
        'new' => 'pending',
        'pending_payment' => 'pending',
        'payment_review' => 'on-hold',
        'holded' => 'on-hold',
        'processing' => 'processing',
        'complete' => 'completed',
        'closed' => 'refunded',
        'canceled' => 'cancelled',
    ];
    private const FINISHED_STATES = ['complete', 'closed'];
    /** Order line types whose product_id is a product the product mapper mirrors. */
    private const MIRRORED_TYPES = ['simple', 'virtual', 'downloadable', 'configurable'];
    private const GIFT_CARD = 'giftcard';
    private const PRODUCT_TAX = 'product';
    private const SHIPPING_TAX = 'shipping';
    private const PERCENT_PLACES = 2;
    private const METHOD_TITLE = 'method_title';
    private const DETAILS = [
        'increment_id', 'state', 'status', 'store_id', 'store_name', 'customer_group_id', 'customer_is_guest',
        'base_currency_code', 'base_grand_total', 'base_to_order_rate', 'total_paid', 'total_refunded', 'total_due',
        'applied_rule_ids', 'discount_description', 'shipping_description', 'weight', 'remote_ip', 'is_virtual',
        'customer_dob', 'customer_taxvat',
    ];
    private const PAYMENT_DETAILS = ['method', 'cc_type', 'cc_last4', 'last_trans_id', 'amount_paid', 'amount_ordered', 'po_number'];

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
        $orderId = $node->string('increment_id');
        $extension = $node->child('extension_attributes');
        $items = $node->list('items');
        $itemTaxes = $extension?->list('item_applied_taxes') ?? [];
        $billing = $node->child('billing_address');
        $assignment = ($extension?->list('shipping_assignments') ?? [])[0] ?? null;
        $shipping = $assignment?->child('shipping');
        $customerId = $node->optionalInt('customer_id') ?? 0;
        $state = $node->optionalString('state') ?? '';
        $status = self::status($state);
        $paid = !Decimal::isZero($node->optionalDecimal('total_paid'));
        $payment = $node->child('payment');
        $email = $node->optionalString('customer_email');
        $lines = self::topLines($items);

        return new Desired(Kind::Order, $node->id(), [
            'name' => "#{$orderId}",
            'created_at' => Fields::requiredTime($node, 'created_at'),
            'currency' => $node->string('order_currency_code'),
            'prices_include_tax' => false,
            'customer_gid' => $customerId > 0 && $node->optionalInt('customer_is_guest') !== 1 ? Fields::id('Customer', $customerId) : null,
            'email' => $email === null ? null : strtolower($email),
            'phone' => $billing?->optionalString('telephone'),
            'billing' => Fields::address($billing),
            'shipping' => Fields::address($shipping?->child('address')),
            'tags' => [],
            'coupons' => self::coupons($node),
            'line_items' => array_map(static fn(Node $line): array => self::lineItem($line, $items, $itemTaxes), $lines),
            'shipping_lines' => $node->optionalInt('is_virtual') === 1 ? [] : [[
                'title' => $node->optionalString('shipping_description') ?? 'Shipping',
                'code' => $shipping?->optionalString('method'),
                'source' => self::SOURCE,
                'total' => Fields::decimalOrZero($node, 'shipping_amount'),
                'taxes' => self::taxes($itemTaxes, self::SHIPPING_TAX, null),
            ]],
            'tax_lines' => self::taxLines($node, $extension?->list('applied_taxes') ?? []),
            'discount_total' => Fields::magnitude(Fields::decimalOrZero($node, 'discount_amount')),
            'shipping_total' => Fields::decimalOrZero($node, 'shipping_amount'),
            'tax_total' => Fields::decimalOrZero($node, 'tax_amount'),
            'total' => Fields::decimalOrZero($node, 'grand_total'),
            'status' => $status,
            'processed_at' => $paid ? Fields::time($node, 'created_at') : null,
            'closed_at' => self::closedAt($node, $state),
            'customer_note' => $node->optionalString('customer_note'),
            'source_name' => self::SOURCE,
            'payment_method_title' => self::paymentTitle($extension, $payment),
            'transaction_id' => $payment?->optionalString('last_trans_id'),
            'fees' => [],
            'refunds' => array_map(static fn(Node $memo): array => self::refund($memo, $lines), $node->list('credit_memos')),
            'notes' => self::notes($node),
            'order' => Fields::details($node, self::DETAILS),
            'payment' => $payment === null ? null : Fields::details($payment, self::PAYMENT_DETAILS),
        ]);
    }

    /** A Magento order state → a WooCommerce order status (a partly refunded order keeps its state). */
    public static function status(string $state): string
    {
        return self::STATES[$state] ?? 'pending';
    }

    /**
     * The lines a customer sees: every item that isn't a child of another (a configurable product's child).
     *
     * @param list<Node> $items
     *
     * @return list<Node>
     */
    private static function topLines(array $items): array
    {
        return array_values(array_filter($items, static fn(Node $item): bool => $item->optionalInt('parent_item_id') === null));
    }

    /**
     * @param list<Node> $items
     * @param list<Node> $itemTaxes
     *
     * @return array<string, mixed>
     */
    private static function lineItem(Node $line, array $items, array $itemTaxes): array
    {
        $itemId = $line->int('item_id');
        $child = null;
        foreach ($items as $item) {
            if ($item->optionalInt('parent_item_id') === $itemId) {
                $child = $item;
                break;
            }
        }
        $type = $line->optionalString('product_type') ?? '';
        $productId = $line->optionalInt('product_id') ?? 0;
        $mirrored = $productId > 0 && in_array($type, self::MIRRORED_TYPES, true);
        $variantId = $child?->optionalInt('product_id') ?? $productId;
        $subtotal = Fields::decimalOrZero($line, 'row_total');
        $taxes = self::taxes($itemTaxes, self::PRODUCT_TAX, [$itemId, $child?->int('item_id')]);

        return [
            'gid' => Fields::id('OrderItem', $itemId),
            'name' => $line->optionalString('name') ?? $line->string('sku'),
            'gift_card' => $type === self::GIFT_CARD,
            'product_gid' => $mirrored ? Fields::productId($productId) : null,
            'variant_gid' => $mirrored ? Fields::variantId($variantId) : null,
            'sku' => $line->string('sku'),
            'quantity' => Fields::quantity($line, 'qty_ordered'),
            'taxable' => $taxes !== [],
            'subtotal' => $subtotal,
            'total' => Decimal::subtract($subtotal, Fields::decimalOrZero($line, 'discount_amount')),
            'taxes' => $taxes,
            'attributes' => [],
        ];
    }

    /** @return list<string> */
    private static function coupons(Node $order): array
    {
        $codes = $order->child('extension_attributes')?->strings('coupon_codes') ?? [];
        $code = $order->optionalString('coupon_code');
        if ($code !== null && !in_array($code, $codes, true)) {
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * The taxes Magento applied to one kind of line (to the named items, or to shipping), as WooCommerce line
     * taxes.
     *
     * @param list<Node> $itemTaxes
     * @param list<?int>|null $itemIds
     *
     * @return list<array{title: string, rate: ?string, amount: string}>
     */
    private static function taxes(array $itemTaxes, string $type, ?array $itemIds): array
    {
        $applied = [];
        foreach ($itemTaxes as $itemTax) {
            if ($itemTax->optionalString('type') !== $type || ($itemIds !== null && !in_array($itemTax->optionalInt('item_id'), $itemIds, true))) {
                continue;
            }
            foreach ($itemTax->list('applied_taxes') as $tax) {
                $applied[] = ['title' => self::taxTitle($tax), 'rate' => self::rate($tax), 'amount' => Fields::decimalOrZero($tax, 'amount')];
            }
        }

        return $applied;
    }

    /**
     * One tax line per applied tax; an order whose tax Magento reports only as a total gets one order-level line.
     *
     * @param list<Node> $appliedTaxes
     *
     * @return list<array{title: string, rate: ?string, order_amount: ?string}>
     */
    private static function taxLines(Node $order, array $appliedTaxes): array
    {
        $lines = [];
        foreach ($appliedTaxes as $tax) {
            $lines[self::taxTitle($tax)] ??= ['title' => self::taxTitle($tax), 'rate' => self::rate($tax), 'order_amount' => null];
        }
        $total = Fields::decimalOrZero($order, 'tax_amount');
        if ($lines === [] && !Decimal::isZero($total)) {
            return [['title' => 'Tax', 'rate' => null, 'order_amount' => $total]];
        }

        return array_values($lines);
    }

    private static function taxTitle(Node $tax): string
    {
        return $tax->optionalString('title') ?? $tax->optionalString('code') ?? 'Tax';
    }

    /** A tax's percent as a fraction (5 % → "0.05"). */
    private static function rate(Node $tax): ?string
    {
        $percent = $tax->optionalDecimal('percent');

        return $percent === null ? null : Decimal::shift($percent, -self::PERCENT_PLACES);
    }

    private static function paymentTitle(?Node $extension, ?Node $payment): ?string
    {
        foreach ($extension?->list('payment_additional_info') ?? [] as $info) {
            if ($info->optionalString('key') === self::METHOD_TITLE) {
                return $info->optionalString('value');
            }
        }

        return $payment?->optionalString('method');
    }

    /** When the order was finished: its last shipment, else its last update once complete or closed. */
    private static function closedAt(Node $order, string $state): ?string
    {
        $times = array_filter(array_map(static fn(Node $shipment): ?string => Fields::time($shipment, 'created_at'), $order->list('shipments')));
        rsort($times);

        return $times[0] ?? (in_array($state, self::FINISHED_STATES, true) ? Fields::time($order, 'updated_at') : null);
    }

    /**
     * A credit memo: its total, its first comment as the reason, and the quantities of the customer's lines it
     * refunds.
     *
     * @param list<Node> $lines
     *
     * @return array<string, mixed>
     */
    private static function refund(Node $memo, array $lines): array
    {
        $lineIds = array_map(static fn(Node $line): int => $line->int('item_id'), $lines);
        $refunded = [];
        foreach ($memo->list('items') as $item) {
            $quantity = Fields::quantity($item, 'qty');
            if ($quantity > 0 && in_array($item->int('order_item_id'), $lineIds, true)) {
                $refunded[] = [
                    'line_item_gid' => Fields::id('OrderItem', $item->int('order_item_id')),
                    'quantity' => $quantity,
                    'subtotal' => Fields::decimalOrZero($item, 'row_total'),
                ];
            }
        }
        $comments = $memo->list('comments');

        return [
            'gid' => Fields::id('CreditMemo', $memo->int('entity_id')),
            'created_at' => Fields::time($memo, 'created_at'),
            'note' => ($comments[0] ?? null)?->optionalString('comment'),
            'amount' => Fields::decimalOrZero($memo, 'grand_total'),
            'lines' => $refunded,
        ];
    }

    /**
     * Each shipment (carriers and tracking numbers) once, and each comment on the order.
     *
     * @return list<array{gid: string, created_at: string, author: ?string, message: string}>
     */
    private static function notes(Node $order): array
    {
        $created = Fields::requiredTime($order, 'created_at');
        $notes = [];
        foreach ($order->list('shipments') as $shipment) {
            $tracks = array_map(
                static fn(Node $track): string => trim(($track->optionalString('title') ?? $track->optionalString('carrier_code') ?? '') . ' ' . ($track->optionalString('track_number') ?? '')),
                $shipment->list('tracks'),
            );
            $notes[] = [
                'gid' => Fields::id('Shipment', $shipment->int('entity_id')),
                'created_at' => Fields::time($shipment, 'created_at') ?? $created,
                'author' => null,
                'message' => trim('Magento shipment ' . ($shipment->optionalString('increment_id') ?? '') . ': ' . implode(', ', array_filter($tracks)), ': '),
            ];
        }
        foreach ($order->list('status_histories') as $history) {
            $comment = $history->optionalString('comment');
            if ($comment !== null) {
                $notes[] = [
                    'gid' => Fields::id('OrderComment', $history->int('entity_id')),
                    'created_at' => Fields::time($history, 'created_at') ?? $created,
                    'author' => null,
                    'message' => $comment,
                ];
            }
        }

        return $notes;
    }
}
