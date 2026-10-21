<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\HubSpot\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A commerce order → a WooCommerce order: its name as the order number, currency, created date, shipping
 * address and the contact who placed it (the first associated contact, mirrored as a customer), with its line
 * items (name, quantity, unit price and product, as the line items' own properties name them). HubSpot's
 * order guide documents no property for the order's totals, taxes, shipping cost or discounts, nor the values of
 * its fulfillment status and pipeline stages, so the total is the sum of the line items, the order is
 * processing, and every order property the account can set (whatever totals and statuses the portal holds) is
 * meta, its fulfillment status and stage included; the tracking number is a note. Verifying the amount and
 * status properties against a live portal is the planned next step.
 */
final class OrderMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = [
        'hs_order_name', 'hs_currency_code', 'hs_createdate', 'hs_shipping_address_street', 'hs_shipping_address_city',
        'hs_shipping_address_state', 'hs_shipping_address_postal_code', 'hs_shipping_tracking_number',
    ];
    private const LINE_NATIVE = ['name', 'quantity', 'price', 'hs_product_id'];

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
        $id = Fields::sourceId($node);
        $lines = array_map(self::lineItem(...), $node->list('line_items'));
        $contacts = Fields::associated($node, 'contacts');
        $tracking = Fields::property($node, 'hs_shipping_tracking_number');
        $created = Fields::time($node, 'hs_createdate') ?? Times::requiredIso($node, 'createdAt');

        return new Desired(Kind::Order, $node->id(), [
            'name' => Fields::property($node, 'hs_order_name') ?? $id,
            'created_at' => $created,
            'currency' => strtoupper(Fields::property($node, 'hs_currency_code') ?? throw new Unsupported("order {$id} names no currency (hs_currency_code).")),
            'prices_include_tax' => false,
            'customer_gid' => isset($contacts[0]) ? Fields::id('Customer', $contacts[0]) : null,
            'email' => null,
            'phone' => null,
            'billing' => [],
            'shipping' => array_filter([
                'address_1' => Fields::property($node, 'hs_shipping_address_street'),
                'city' => Fields::property($node, 'hs_shipping_address_city'),
                'state' => Fields::property($node, 'hs_shipping_address_state'),
                'postcode' => Fields::property($node, 'hs_shipping_address_postal_code'),
            ], static fn(?string $value): bool => $value !== null),
            'coupons' => [],
            'line_items' => array_map(static fn(array $line): array => $line['item'], $lines),
            'shipping_lines' => [],
            'tax_lines' => [],
            'discount_total' => '0',
            'shipping_total' => '0',
            'tax_total' => '0',
            'total' => Decimal::sum(array_map(static fn(array $line): string => $line['item']['total'], $lines)),
            'status' => 'processing',
            'processed_at' => null,
            'closed_at' => null,
            'customer_note' => null,
            'payment_method_title' => null,
            'transaction_id' => null,
            'fees' => [],
            'refunds' => [],
            'notes' => $tracking === null ? [] : [['gid' => Fields::id('Tracking', $id), 'author' => null, 'message' => "Tracking {$tracking}", 'created_at' => $created]],
            'original_meta' => [],
            'order' => Fields::details($node, self::NATIVE),
            'line_details' => array_combine(array_map(static fn(array $line): string => $line['item']['gid'], $lines), array_map(static fn(array $line): array => $line['details'], $lines)),
        ]);
    }

    /**
     * @return array{
     *     item: array{gid: string, name: string, product_gid: ?string, variant_gid: ?string, sku: null, quantity: int, subtotal: string, total: string, taxes: list<never>, attributes: array<never, never>},
     *     details: array<array-key, mixed>,
     * }
     */
    private static function lineItem(Node $line): array
    {
        $quantity = (int) (Fields::property($line, 'quantity') ?? '1');
        $price = Decimal::of(Fields::property($line, 'price') ?? '0');
        $product = Fields::property($line, 'hs_product_id');
        $total = Decimal::multiply($price, $quantity);

        return [
            'item' => [
                'gid' => Fields::id('LineItem', Fields::sourceId($line)),
                'name' => Fields::property($line, 'name') ?? '',
                'product_gid' => $product === null ? null : Fields::id('Product', $product),
                'variant_gid' => $product === null ? null : Fields::id('Variant', $product),
                'sku' => null,
                'quantity' => $quantity,
                'subtotal' => $total,
                'total' => $total,
                'taxes' => [],
                'attributes' => [],
            ],
            'details' => Fields::details($line, self::LINE_NATIVE),
        ];
    }
}
