<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * An order → a WooCommerce order with BigCommerce's totals. Lines point at the product and its variant (see
 * Fields::variantId) with their discounts and taxes; each shipping address becomes a shipping line (its
 * handling cost a fee), gift wrapping and the order's fees become fees, coupon codes coupon lines, refunds
 * WooCommerce refunds, and each shipment (carrier, tracking) and the staff notes order notes. BigCommerce's
 * status ids map to WooCommerce statuses (see status()). Incomplete (abandoned checkout) and archived
 * orders map to nothing. Payment, channel, currency-conversion, store-credit and gift-certificate details
 * are mirrored as meta.
 */
final class OrderMapper implements Mapper
{
    public const SOURCE = 'bigcommerce';

    private const VERSION = 1;
    private const INCOMPLETE = 0;
    private const PARTIALLY_REFUNDED = 14;
    /** BigCommerce order status id → WooCommerce status. */
    private const STATUS = [
        1 => 'pending',      // Pending: checkout started, not paid
        2 => 'completed',    // Shipped
        3 => 'processing',   // Partially Shipped
        4 => 'refunded',     // Refunded
        5 => 'cancelled',    // Cancelled
        6 => 'failed',       // Declined
        7 => 'on-hold',      // Awaiting Payment (offline payment methods)
        8 => 'processing',   // Awaiting Pickup
        9 => 'processing',   // Awaiting Shipment
        10 => 'completed',   // Completed
        11 => 'processing',  // Awaiting Fulfillment
        12 => 'on-hold',     // Manual Verification Required
        13 => 'on-hold',     // Disputed
        14 => 'processing',  // Partially Refunded (completed once shipped)
    ];
    private const PAID = ['captured', 'paid', 'partially refunded', 'refunded'];
    private const TAX_ITEM = 'item';
    private const TAX_SHIPPING = 'shipping';
    private const TAX_HANDLING = 'handling';
    private const TAX_WRAPPING = 'gift-wrapping';
    private const GIFT_CERTIFICATE = 'giftcertificate';
    private const REFUNDED_PRODUCT = 'PRODUCT';
    private const PERCENT_PLACES = 2;
    private const DETAILS = [
        'status', 'status_id', 'payment_status', 'payment_provider_id', 'channel_id', 'order_source', 'external_source',
        'external_id', 'external_order_id', 'ebay_order_id', 'geoip_country_iso2', 'customer_locale', 'ip_address',
        'currency_exchange_rate', 'default_currency_code', 'store_default_currency_code', 'store_credit_amount',
        'gift_certificate_amount', 'refunded_amount', 'items_total', 'items_shipped', 'order_is_digital',
        'is_email_opt_in', 'staff_notes', 'coupons',
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
        $statusId = $node->int('status_id');
        if ($statusId === self::INCOMPLETE || ($node->raw()['is_deleted'] ?? false) === true) {
            return null;
        }
        $orderId = Fields::sourceId($node);
        $taxes = $node->list('taxes');
        $billing = $node->child('billing_address');
        $addresses = $node->list('shipping_addresses');
        [$shippingLines, $handling] = self::shipping($addresses, $taxes);
        $email = $billing?->optionalString('email');
        $customerId = $node->optionalInt('customer_id') ?? 0;
        $paid = in_array($node->optionalString('payment_status'), self::PAID, true);

        return new Desired(Kind::Order, $node->id(), [
            'name' => "#{$orderId}",
            'created_at' => Fields::requiredTime($node, 'date_created'),
            'currency' => $node->string('currency_code'),
            'prices_include_tax' => ($node->raw()['is_tax_inclusive_pricing'] ?? false) === true,
            'customer_gid' => $customerId > 0 ? Fields::id('Customer', $customerId) : null,
            'email' => $email === null ? null : strtolower($email),
            'phone' => $billing?->optionalString('phone'),
            'billing' => Fields::orderAddress($billing),
            'shipping' => Fields::orderAddress($addresses[0] ?? null),
            'tags' => [],
            'coupons' => array_values(array_filter(array_map(static fn(Node $coupon): ?string => $coupon->optionalString('code'), $node->list('coupons')))),
            'line_items' => array_map(static fn(Node $line): array => self::lineItem($line, $taxes), $node->list('products')),
            'shipping_lines' => $shippingLines,
            'tax_lines' => self::taxLines($node, $taxes),
            'discount_total' => Decimal::sum([Fields::decimalOrZero($node, 'discount_amount'), Fields::decimalOrZero($node, 'coupon_discount')]),
            'shipping_total' => Fields::decimalOrZero($node, 'shipping_cost_ex_tax'),
            'tax_total' => Fields::decimalOrZero($node, 'total_tax'),
            'total' => Fields::decimalOrZero($node, 'total_inc_tax'),
            'status' => self::status($statusId, Fields::time($node, 'date_shipped') !== null),
            'processed_at' => $paid ? Fields::time($node, 'date_created') : null,
            'closed_at' => Fields::time($node, 'date_shipped'),
            'customer_note' => $node->optionalString('customer_message'),
            'source_name' => self::SOURCE,
            'payment_method_title' => $node->optionalString('payment_method'),
            'transaction_id' => null,
            'fees' => [...$handling, ...self::wrapping($node, $taxes), ...self::fees($node)],
            'refunds' => array_map(static fn(Node $refund): array => self::refund($refund), $node->list('refunds')),
            'notes' => self::notes($node),
            'order' => Fields::details($node, self::DETAILS),
        ]);
    }

    /** A BigCommerce order status id (and whether the order has shipped) → a WooCommerce order status. */
    public static function status(int $statusId, bool $shipped): string
    {
        if ($statusId === self::PARTIALLY_REFUNDED && $shipped) {
            return 'completed';
        }

        return self::STATUS[$statusId] ?? 'pending';
    }

    /**
     * @param list<Node> $taxes
     *
     * @return array<string, mixed>
     */
    private static function lineItem(Node $line, array $taxes): array
    {
        $id = $line->int('id');
        $productId = $line->optionalInt('product_id') ?? 0;
        $variantId = $line->optionalInt('variant_id') ?? 0;
        $subtotal = Fields::decimalOrZero($line, 'total_ex_tax');
        $discounts = array_map(static fn(Node $discount): string => Fields::decimalOrZero($discount, 'amount'), $line->list('applied_discounts'));
        $lineTaxes = self::taxes($taxes, self::TAX_ITEM, static fn(Node $tax): bool => $tax->optionalInt('order_product_id') === $id);

        return [
            'gid' => Fields::id('OrderProduct', $id),
            'name' => $line->optionalString('name') ?? '',
            'gift_card' => $line->optionalString('type') === self::GIFT_CERTIFICATE,
            'product_gid' => $productId > 0 ? Fields::productId($productId) : null,
            'variant_gid' => $productId > 0 && $variantId > 0 ? Fields::variantId($variantId) : null,
            'sku' => $line->optionalString('sku'),
            'quantity' => $line->int('quantity'),
            'taxable' => $lineTaxes !== [],
            'subtotal' => $subtotal,
            'total' => Decimal::subtract($subtotal, Decimal::sum($discounts)),
            'taxes' => $lineTaxes,
            'attributes' => self::attributes($line),
        ];
    }

    /**
     * What the customer chose and wrote for a line: its options, configurable fields, gift wrapping and event.
     *
     * @return array<string, string>
     */
    private static function attributes(Node $line): array
    {
        $attributes = [];
        foreach ($line->list('product_options') as $option) {
            $name = $option->optionalString('display_name_customer') ?? $option->optionalString('display_name');
            $value = $option->optionalString('display_value_customer') ?? $option->optionalString('display_value');
            if ($name !== null && $value !== null) {
                $attributes[$name] = $value;
            }
        }
        foreach ($line->list('configurable_fields') as $field) {
            $name = $field->optionalString('name');
            $value = $field->optionalString('value');
            if ($name !== null && $value !== null) {
                $attributes[$name] = $value;
            }
        }

        return [...$attributes, ...array_filter([
            'Gift wrapping' => $line->optionalString('wrapping_name'),
            'Gift message' => $line->optionalString('wrapping_message'),
            'Event' => $line->optionalString('event_name'),
            'Event date' => $line->optionalString('event_date'),
        ], static fn(?string $value): bool => $value !== null)];
    }

    /**
     * One shipping line per shipping address, and each address's handling cost as a fee.
     *
     * @param list<Node> $addresses
     * @param list<Node> $taxes
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function shipping(array $addresses, array $taxes): array
    {
        $lines = [];
        $handling = [];
        foreach ($addresses as $address) {
            $addressId = $address->int('id');
            $forAddress = static fn(Node $tax): bool => $tax->optionalInt('order_address_id') === $addressId;
            $lines[] = [
                'title' => $address->optionalString('shipping_method') ?? 'Shipping',
                'code' => null,
                'source' => self::SOURCE,
                'total' => Fields::decimalOrZero($address, 'cost_ex_tax'),
                'taxes' => self::taxes($taxes, self::TAX_SHIPPING, $forAddress),
            ];
            $cost = Fields::decimalOrZero($address, 'handling_cost_ex_tax');
            if (!Decimal::isZero($cost)) {
                $handling[] = ['name' => 'Handling', 'total' => $cost, 'taxes' => self::taxes($taxes, self::TAX_HANDLING, $forAddress)];
            }
        }

        return [$lines, $handling];
    }

    /**
     * Gift wrapping for the whole order, as a fee.
     *
     * @param list<Node> $taxes
     *
     * @return list<array<string, mixed>>
     */
    private static function wrapping(Node $order, array $taxes): array
    {
        $cost = Fields::decimalOrZero($order, 'wrapping_cost_ex_tax');

        return Decimal::isZero($cost) ? [] : [[
            'name' => 'Gift wrapping',
            'total' => $cost,
            'taxes' => self::taxes($taxes, self::TAX_WRAPPING, static fn(Node $tax): bool => true),
        ]];
    }

    /**
     * The order's fees (included in the order when it is read). BigCommerce doesn't say which tax a fee's tax
     * is, so a fee carries no tax line; its tax is in the order's tax total and meta.
     *
     * @return list<array<string, mixed>>
     */
    private static function fees(Node $order): array
    {
        $fees = $order->raw()['fees'] ?? null;
        if (!is_array($fees) || !array_is_list($fees)) {
            return [];
        }

        return array_map(static fn(Node $fee): array => [
            'name' => $fee->optionalString('display_name_customer') ?? $fee->optionalString('display_name_merchant') ?? 'Fee',
            'total' => Fields::decimalOrZero($fee, 'cost_ex_tax'),
            'taxes' => [],
        ], $order->list('fees'));
    }

    /**
     * The tax rows of one kind of line that belong to it, as WooCommerce line taxes (rate as a fraction).
     *
     * @param list<Node> $taxes
     * @param callable(Node): bool $belongs
     *
     * @return list<array{title: string, rate: ?string, amount: string}>
     */
    private static function taxes(array $taxes, string $lineType, callable $belongs): array
    {
        $applied = [];
        foreach ($taxes as $tax) {
            if ($tax->optionalString('line_item_type') === $lineType && $belongs($tax)) {
                $applied[] = ['title' => self::taxTitle($tax), 'rate' => self::rate($tax), 'amount' => Fields::decimalOrZero($tax, 'line_amount')];
            }
        }

        return $applied;
    }

    /**
     * One tax line per tax; an order whose tax BigCommerce reports only as a total gets one order-level line.
     *
     * @param list<Node> $taxes
     *
     * @return list<array{title: string, rate: ?string, order_amount: ?string}>
     */
    private static function taxLines(Node $order, array $taxes): array
    {
        $lines = [];
        foreach ($taxes as $tax) {
            $lines[self::taxTitle($tax)] ??= ['title' => self::taxTitle($tax), 'rate' => self::rate($tax), 'order_amount' => null];
        }
        $total = Fields::decimalOrZero($order, 'total_tax');
        if ($lines === [] && !Decimal::isZero($total)) {
            return [['title' => 'Tax', 'rate' => null, 'order_amount' => $total]];
        }

        return array_values($lines);
    }

    private static function taxTitle(Node $tax): string
    {
        return $tax->optionalString('name') ?? $tax->optionalString('class') ?? 'Tax';
    }

    /** A tax's rate as a fraction ("5.0000" % → "0.05"). */
    private static function rate(Node $tax): ?string
    {
        $rate = $tax->optionalDecimal('rate');

        return $rate === null ? null : Decimal::shift($rate, -self::PERCENT_PLACES);
    }

    /** @return array<string, mixed> */
    private static function refund(Node $refund): array
    {
        $lines = [];
        foreach ($refund->list('items') as $item) {
            $quantity = $item->optionalInt('quantity') ?? 0;
            if ($item->optionalString('item_type') === self::REFUNDED_PRODUCT && $quantity > 0) {
                $lines[] = [
                    'line_item_gid' => Fields::id('OrderProduct', $item->int('item_id')),
                    'quantity' => $quantity,
                    'subtotal' => Fields::decimalOrZero($item, 'requested_amount'),
                ];
            }
        }

        return [
            'gid' => Fields::id('Refund', $refund->int('id')),
            'created_at' => Fields::time($refund, 'created'),
            'note' => $refund->optionalString('reason'),
            'amount' => Fields::decimalOrZero($refund, 'total_amount'),
            'lines' => $lines,
        ];
    }

    /**
     * Each shipment (carrier and tracking) once, and the staff notes.
     *
     * @return list<array{gid: string, created_at: string, author: ?string, message: string}>
     */
    private static function notes(Node $order): array
    {
        $notes = [];
        foreach ($order->list('shipments') as $shipment) {
            $carrier = $shipment->optionalString('shipping_provider_display_name') ?? $shipment->optionalString('tracking_carrier');
            $link = $shipment->optionalString('tracking_link') ?? $shipment->optionalString('generated_tracking_link');
            $notes[] = [
                'gid' => Fields::id('Shipment', $shipment->int('id')),
                'created_at' => Fields::time($shipment, 'date_created') ?? Fields::requiredTime($order, 'date_created'),
                'author' => null,
                'message' => trim('BigCommerce shipment: ' . implode(' ', array_filter([$carrier, $shipment->optionalString('tracking_number'), $link]))),
            ];
        }
        $staffNotes = $order->optionalString('staff_notes');
        if ($staffNotes !== null) {
            $notes[] = ['gid' => Fields::id('StaffNote', Fields::sourceId($order)), 'created_at' => Fields::requiredTime($order, 'date_created'), 'author' => 'Staff', 'message' => $staffNotes];
        }

        return $notes;
    }
}
