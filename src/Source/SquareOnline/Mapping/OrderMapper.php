<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\SquareOnline\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A sale order → a WooCommerce order with Square's totals. Line items point at the item and its variation
 * (see Fields::variationId) with their own taxes; shipping and delivery service charges become shipping
 * lines, other service charges, tips and cash rounding become fees; Square's discounts (catalog or ad hoc,
 * never codes) are in the discount total and mirrored as meta. Approved and pending refunds become
 * WooCommerce refunds, and each fulfillment state an order note. An exchange's new items are its order (the
 * credit from the returned goods is meta); what it returned, like a return order's, is a refund on the sale
 * it came from (ReturnMapper). An order WooCommerce Square pushed to Square from a WooCommerce order names
 * that original (`original_meta`), so it is never mirrored back as a second copy. Orders mixing inclusive
 * and additive taxes have no WooCommerce equivalent.
 */
final class OrderMapper implements Mapper
{
    public const SOURCE = 'square';

    private const VERSION = 2;
    /**
     * Where WooCommerce Square keeps the Square order it pushed for a WooCommerce order: each of its payment
     * gateways stores it as `_wc_<gateway id>_square_order_id` (woocommerce/woocommerce-square at a4ca019,
     * includes/Plugin.php gateway ids, Payment_Gateway::get_order_meta_prefix and Gateway.php).
     */
    public const WOOCOMMERCE_SQUARE_ORDER_META = [
        '_wc_square_credit_card_square_order_id',
        '_wc_square_cash_app_pay_square_order_id',
        '_wc_gift_cards_pay_square_order_id',
    ];
    private const STATUS = ['OPEN' => 'processing', 'COMPLETED' => 'completed', 'CANCELED' => 'cancelled'];
    private const MIRRORED_REFUND_STATUSES = ['APPROVED', 'PENDING'];
    private const INCLUSIVE = 'INCLUSIVE';
    private const APPORTIONED = 'APPORTIONED_TREATMENT';
    private const GIFT_CARD = 'GIFT_CARD';
    private const SHIPPED_FULFILLMENTS = ['SHIPMENT', 'DELIVERY'];
    private const SHIPPING_CHARGE_PATTERN = '/\b(shipping|delivery)\b/i';
    /** Each fulfillment state → the timestamp Square records it with (shipments, pickups, deliveries). */
    private const STATE_TIMES = [
        'PROPOSED' => ['placed_at'],
        'RESERVED' => ['in_progress_at', 'accepted_at'],
        'PREPARED' => ['packaged_at', 'ready_at', 'prepared_at'],
        'COMPLETED' => ['shipped_at', 'picked_up_at', 'delivered_at', 'completed_at'],
        'CANCELED' => ['canceled_at'],
        'FAILED' => ['failed_at', 'rejected_at', 'expired_at'],
    ];
    private const DETAILS = ['SHIPMENT' => 'shipment_details', 'PICKUP' => 'pickup_details', 'DELIVERY' => 'delivery_details', 'IN_STORE' => 'in_store_details'];

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
        $orderId = Fields::squareId($node);
        $taxes = self::taxes($node);
        $recipient = self::recipient($node);
        $address = Fields::address(
            $recipient?->child('address'),
            $recipient?->optionalString('display_name'),
            null,
            null,
            $recipient?->optionalString('phone_number'),
        );
        $email = $recipient?->optionalString('email_address');
        $tenders = $node->list('tenders');
        [$shipping, $shippingTotal, $charges] = self::serviceCharges($node, $taxes);
        $refunds = array_values(array_filter($node->list('refunds'), static fn(Node $refund): bool => in_array($refund->string('status'), self::MIRRORED_REFUND_STATUSES, true)));
        $customerId = $node->optionalString('customer_id');

        return new Desired(Kind::Order, $node->id(), [
            'name' => "#{$orderId}",
            'created_at' => Fields::requiredTime($node, 'created_at'),
            'currency' => $node->requiredChild('total_money')->string('currency'),
            'prices_include_tax' => self::pricesIncludeTax($node),
            'customer_gid' => $customerId === null ? null : Fields::id('Customer', $customerId),
            'email' => $email === null ? null : strtolower($email),
            'phone' => $recipient?->optionalString('phone_number'),
            'billing' => $address,
            'shipping' => $address,
            'tags' => [],
            'coupons' => [],
            'line_items' => array_map(static fn(Node $line): array => self::lineItem($node, $line, $taxes), $node->list('line_items')),
            'shipping_lines' => $shipping,
            'tax_lines' => array_values(array_map(static fn(array $tax): array => ['title' => $tax['title'], 'rate' => $tax['rate'], 'order_amount' => null], $taxes)),
            'discount_total' => Fields::moneyOrZero($node, 'total_discount_money'),
            'shipping_total' => $shippingTotal,
            'tax_total' => Fields::moneyOrZero($node, 'total_tax_money'),
            'total' => Fields::moneyOrZero($node, 'total_money'),
            'status' => self::status($node->string('state'), self::isPaid($node), self::isRefunded($node, $refunds)),
            'state' => $node->string('state'),
            'processed_at' => $tenders === [] ? null : Fields::time($tenders[0], 'created_at'),
            'closed_at' => Fields::time($node, 'closed_at'),
            'customer_note' => self::customerNote($node),
            'source_name' => $node->child('source')?->optionalString('name'),
            'location_id' => $node->string('location_id'),
            'reference_id' => $node->optionalString('reference_id'),
            'ticket_name' => $node->optionalString('ticket_name'),
            'payment_method_title' => $tenders === [] ? null : 'Square (' . implode(', ', array_values(array_unique(array_map(static fn(Node $tender): string => $tender->string('type'), $tenders)))) . ')',
            'transaction_id' => $tenders === [] ? null : $tenders[0]->optionalString('payment_id') ?? $tenders[0]->optionalString('id'),
            'fees' => [...$charges, ...self::adjustments($node)],
            'discounts' => array_map(static fn(Node $discount): array => [
                'name' => $discount->optionalString('name'),
                'type' => $discount->optionalString('type'),
                'percentage' => $discount->optionalString('percentage'),
                'applied' => Fields::money($discount, 'applied_money'),
                'scope' => $discount->optionalString('scope'),
                'catalog_object_id' => $discount->optionalString('catalog_object_id'),
            ], $node->list('discounts')),
            'refunds' => array_map(static fn(Node $refund): array => [
                'gid' => Fields::id('Refund', $refund->string('id')),
                'created_at' => Fields::time($refund, 'created_at'),
                'note' => $refund->optionalString('reason'),
                'amount' => Fields::moneyOrZero($refund, 'amount_money'),
                'lines' => [],
            ], $refunds),
            'notes' => self::notes($node),
            'exchange_credit' => self::exchangeCredit($node),
            'original_meta' => array_map(static fn(string $key): array => ['key' => $key, 'value' => $orderId], self::WOOCOMMERCE_SQUARE_ORDER_META),
        ]);
    }

    /** What the goods an exchange took back were worth (null for an order that returns nothing). */
    private static function exchangeCredit(Node $order): ?string
    {
        if ($order->list('returns') === []) {
            return null;
        }
        $amounts = $order->child('return_amounts');

        return $amounts === null ? '0' : Fields::moneyOrZero($amounts, 'total_money');
    }

    /** Square's order state (and whether it is paid or fully refunded) → a WooCommerce order status. */
    public static function status(string $state, bool $paid, bool $refunded): string
    {
        if ($refunded && $state !== 'CANCELED') {
            return 'refunded';
        }
        if ($state === 'OPEN' && !$paid) {
            return 'pending';
        }

        return self::STATUS[$state] ?? 'pending';
    }

    /**
     * The order's taxes by uid: title, rate (a fraction: "7.25" % → "0.0725") and whether included in prices.
     *
     * @return array<string, array{title: string, rate: ?string, inclusive: bool}>
     */
    private static function taxes(Node $order): array
    {
        $taxes = [];
        foreach ($order->list('taxes') as $tax) {
            $percentage = $tax->optionalString('percentage');
            $taxes[(string) $tax->optionalString('uid')] = [
                'title' => $tax->optionalString('name') ?? 'Tax',
                'rate' => $percentage === null ? null : Decimal::shift($percentage, -2),
                'inclusive' => $tax->optionalString('type') === self::INCLUSIVE,
            ];
        }

        return $taxes;
    }

    /**
     * @throws Unsupported when inclusive and additive taxes are mixed (WooCommerce prices either include tax or don't)
     */
    private static function pricesIncludeTax(Node $order): bool
    {
        $inclusive = array_values(array_unique(array_map(static fn(array $tax): bool => $tax['inclusive'], self::taxes($order))));
        if (count($inclusive) > 1) {
            throw new Unsupported('Order ' . Fields::squareId($order) . ' mixes inclusive and additive taxes.');
        }

        return $inclusive === [true];
    }

    /**
     * @param array<string, array{title: string, rate: ?string, inclusive: bool}> $taxes
     *
     * @return array<string, mixed>
     */
    private static function lineItem(Node $order, Node $line, array $taxes): array
    {
        $uid = $line->optionalString('uid') ?? (string) $line->optionalString('catalog_object_id');
        $variationId = $line->optionalString('catalog_object_id');
        $itemId = $variationId === null ? null : $order->child('variation_items')?->optionalString($variationId);
        $total = self::preTax($line);
        $attributes = array_filter([
            'Variation' => $line->optionalString('variation_name'),
            'Modifiers' => implode(', ', array_filter(array_map(self::modifier(...), $line->list('modifiers')))),
            'Note' => $line->optionalString('note'),
        ], static fn(?string $value): bool => $value !== null && $value !== '');

        return [
            'gid' => Fields::id('OrderLineItem', Fields::squareId($order) . '-' . $uid),
            'name' => $line->optionalString('name') ?? $line->optionalString('variation_name') ?? '',
            'gift_card' => $line->optionalString('item_type') === self::GIFT_CARD,
            'product_gid' => $itemId === null ? null : Fields::itemId($itemId),
            'variant_gid' => $itemId === null ? null : Fields::variationId($variationId),
            'sku' => null,
            'quantity' => Fields::wholeQuantity($line->string('quantity'), "Line \"{$line->optionalString('name')}\""),
            'taxable' => $line->list('applied_taxes') !== [],
            'subtotal' => Decimal::sum([$total, Fields::moneyOrZero($line, 'total_discount_money')]),
            'total' => $total,
            'taxes' => self::appliedTaxes($line, $taxes),
            'attributes' => $attributes,
        ];
    }

    private static function modifier(Node $modifier): ?string
    {
        $name = $modifier->optionalString('name');
        $quantity = $modifier->optionalString('quantity');

        return $name === null || $quantity === null || Decimal::normalise($quantity) === '1' ? $name : "{$name} × {$quantity}";
    }

    /**
     * Service charges: those for shipping or delivery (on an order that ships or delivers) are shipping
     * lines, the rest fees.
     *
     * @param array<string, array{title: string, rate: ?string, inclusive: bool}> $taxes
     *
     * @return array{0: list<array<string, mixed>>, 1: string, 2: list<array<string, mixed>>} shipping lines, their total, fees
     */
    private static function serviceCharges(Node $order, array $taxes): array
    {
        $shippingTotals = [];
        $ships = false;
        $shippingTypes = [];
        foreach ($order->list('fulfillments') as $fulfillment) {
            $ships = $ships || in_array($fulfillment->optionalString('type'), self::SHIPPED_FULFILLMENTS, true);
            $shippingTypes[] = $fulfillment->child('shipment_details')?->optionalString('shipping_type');
        }
        $shipping = [];
        $fees = [];
        foreach ($order->list('service_charges') as $charge) {
            if ($charge->optionalString('treatment_type') === self::APPORTIONED) {
                throw new Unsupported('Order ' . Fields::squareId($order) . ' has a service charge apportioned into its line items.');
            }
            $name = $charge->optionalString('name') ?? 'Service charge';
            $total = self::preTax($charge);
            $lineTaxes = self::appliedTaxes($charge, $taxes);
            if ($ships && (preg_match(self::SHIPPING_CHARGE_PATTERN, $name) === 1 || in_array($name, $shippingTypes, true))) {
                $shipping[] = ['title' => $name, 'code' => null, 'source' => self::SOURCE, 'total' => $total, 'taxes' => $lineTaxes];
                $shippingTotals[] = $total;
            } else {
                $fees[] = ['name' => $name, 'total' => $total, 'taxes' => $lineTaxes];
            }
        }

        return [$shipping, Decimal::sum($shippingTotals), $fees];
    }

    /**
     * Tips and cash rounding, as fees.
     *
     * @return list<array<string, mixed>>
     */
    private static function adjustments(Node $order): array
    {
        $fees = [];
        $tip = Fields::moneyOrZero($order, 'total_tip_money');
        if (!Decimal::isZero($tip)) {
            $fees[] = ['name' => 'Tip', 'total' => $tip, 'taxes' => []];
        }
        $rounding = $order->child('rounding_adjustment');
        $amount = $rounding === null ? '0' : Fields::moneyOrZero($rounding, 'amount_money');
        if ($rounding !== null && !Decimal::isZero($amount)) {
            $fees[] = ['name' => $rounding->optionalString('name') ?? 'Rounding', 'total' => $amount, 'taxes' => []];
        }

        return $fees;
    }

    /** What a line or service charge comes to before tax (Square's total less its tax, inclusive or not). */
    private static function preTax(Node $line): string
    {
        return Decimal::subtract(Fields::moneyOrZero($line, 'total_money'), Fields::moneyOrZero($line, 'total_tax_money'));
    }

    /**
     * @param array<string, array{title: string, rate: ?string, inclusive: bool}> $taxes
     *
     * @return list<array{title: string, rate: ?string, amount: string}>
     */
    private static function appliedTaxes(Node $line, array $taxes): array
    {
        $applied = [];
        foreach ($line->list('applied_taxes') as $tax) {
            $order = $taxes[$tax->string('tax_uid')] ?? ['title' => 'Tax', 'rate' => null];
            $applied[] = ['title' => $order['title'], 'rate' => $order['rate'], 'amount' => Fields::moneyOrZero($tax, 'applied_money')];
        }

        return $applied;
    }

    /** Paid: tendered, with nothing left due. */
    private static function isPaid(Node $order): bool
    {
        return $order->list('tenders') !== [] && Decimal::isZero(Fields::money($order, 'net_amount_due_money'));
    }

    /** @param list<Node> $refunds */
    private static function isRefunded(Node $order, array $refunds): bool
    {
        $total = Fields::moneyOrZero($order, 'total_money');
        $approved = array_map(
            static fn(Node $refund): string => Fields::moneyOrZero($refund, 'amount_money'),
            array_filter($refunds, static fn(Node $refund): bool => $refund->string('status') === 'APPROVED'),
        );

        return Decimal::compare($total, '0') > 0 && Decimal::compare(Decimal::sum(array_values($approved)), $total) >= 0;
    }

    /** Who the order goes to: the first fulfillment's recipient. */
    private static function recipient(Node $order): ?Node
    {
        foreach ($order->list('fulfillments') as $fulfillment) {
            $details = self::DETAILS[(string) $fulfillment->optionalString('type')] ?? null;
            $recipient = $details === null ? null : $fulfillment->child($details)?->child('recipient');
            if ($recipient !== null) {
                return $recipient;
            }
        }

        return null;
    }

    private static function customerNote(Node $order): ?string
    {
        foreach ($order->list('fulfillments') as $fulfillment) {
            $note = $fulfillment->child('shipment_details')?->optionalString('shipping_note')
                ?? $fulfillment->child('pickup_details')?->optionalString('note')
                ?? $fulfillment->child('delivery_details')?->optionalString('note');
            if ($note !== null) {
                return $note;
            }
        }

        return null;
    }

    /**
     * One note per fulfillment state reached (its type, state and, for shipments, carrier and tracking).
     *
     * @return list<array{gid: string, created_at: string, author: null, message: string}>
     */
    private static function notes(Node $order): array
    {
        $orderId = Fields::squareId($order);
        $notes = [];
        foreach ($order->list('fulfillments') as $index => $fulfillment) {
            $type = $fulfillment->optionalString('type') ?? 'FULFILLMENT';
            $state = $fulfillment->optionalString('state') ?? 'PROPOSED';
            $details = $fulfillment->child(self::DETAILS[$type] ?? '');
            $at = null;
            foreach (self::STATE_TIMES[$state] ?? [] as $field) {
                $at ??= $details === null ? null : Fields::time($details, $field);
            }
            $tracking = $details === null ? '' : trim(implode(' ', array_filter([
                $details->optionalString('carrier'),
                $details->optionalString('tracking_number'),
                $details->optionalString('tracking_url'),
            ])));
            $uid = $fulfillment->optionalString('uid') ?? "{$orderId}-{$index}";
            $notes[] = [
                'gid' => Fields::id('Fulfillment', "{$uid}-{$state}"),
                'created_at' => $at ?? Fields::requiredTime($order, 'created_at'),
                'author' => null,
                'message' => trim('Square ' . strtolower(str_replace('_', ' ', $type)) . ' ' . strtolower($state) . ($tracking === '' ? '' : ": {$tracking}")),
            ];
        }

        return $notes;
    }
}
