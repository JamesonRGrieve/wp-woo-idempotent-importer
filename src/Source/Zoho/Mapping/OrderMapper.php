<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A sales order with its ordered items → a WooCommerce order with Zoho's totals: its number (else subject),
 * created time, currency (the order's in a multi-currency org, else the org's home currency), the contact it
 * names (a WooCommerce customer), billing and shipping addresses, its sub-total, discount, tax and grand total
 * (the adjustment as a fee), and its status: `Delivered` → completed, `Cancelled` → cancelled, anything else
 * (Created, Approved, a custom status) → processing; the status as Zoho names it is meta. Each item links to its
 * product with its quantity, list price, discount and total; its line taxes are listed by name. Its subject,
 * deal, quote, carrier, purchase order, terms, description, owner and every other field a user can set are meta.
 */
final class OrderMapper implements Mapper
{
    private const VERSION = 1;
    private const STATUSES = ['Delivered' => 'completed', 'Cancelled' => 'cancelled'];
    private const NATIVE = [
        'SO_Number', 'Currency', 'Contact_Name', 'Billing_Street', 'Billing_City', 'Billing_State', 'Billing_Code', 'Billing_Country',
        'Shipping_Street', 'Shipping_City', 'Shipping_State', 'Shipping_Code', 'Shipping_Country', 'Sub_Total', 'Discount', 'Tax',
        'Adjustment', 'Grand_Total', 'Ordered_Items', 'Created_Time',
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
        $id = Fields::sourceId($node);
        $contact = Fields::lookupId($node, 'Contact_Name');
        $adjustment = Fields::text($node, 'Adjustment');
        $tax = Decimal::of(Fields::text($node, 'Tax') ?? '0');
        $items = is_array($node->raw()['Ordered_Items'] ?? null) ? $node->list('Ordered_Items') : [];

        return new Desired(Kind::Order, $node->id(), [
            'name' => Fields::text($node, 'SO_Number') ?? Fields::text($node, 'Subject') ?? $id,
            'created_at' => Times::requiredIso($node, 'Created_Time'),
            'currency' => Fields::text($node, 'Currency') ?? $node->string('org_currency'),
            'prices_include_tax' => false,
            'customer_gid' => $contact === null ? null : Fields::id('Customer', $contact),
            'email' => null,
            'phone' => null,
            'billing' => Fields::storeAddress($node, 'Billing_', 'Billing_Code', 'Billing_Country'),
            'shipping' => Fields::storeAddress($node, 'Shipping_', 'Shipping_Code', 'Shipping_Country'),
            'coupons' => [],
            'line_items' => array_map(static fn(Node $item): array => self::lineItem($item, $id), $items),
            'shipping_lines' => [],
            'tax_lines' => Decimal::isZero($tax) ? [] : [['title' => 'Tax', 'rate' => null, 'order_amount' => $tax]],
            'discount_total' => Decimal::of(Fields::text($node, 'Discount') ?? '0'),
            'shipping_total' => '0',
            'tax_total' => $tax,
            'total' => Decimal::of(Fields::text($node, 'Grand_Total') ?? '0'),
            'status' => self::STATUSES[Fields::text($node, 'Status') ?? ''] ?? 'processing',
            'processed_at' => null,
            'closed_at' => null,
            'customer_note' => null,
            'payment_method_title' => null,
            'transaction_id' => null,
            'fees' => Decimal::isZero($adjustment) || $adjustment === null ? [] : [['name' => 'Adjustment', 'total' => Decimal::of($adjustment), 'taxes' => []]],
            'refunds' => [],
            'notes' => [],
            'original_meta' => [],
            'sub_total' => Fields::text($node, 'Sub_Total'),
            'order' => Fields::details($node, self::NATIVE),
        ]);
    }

    /** @return array<string, mixed> */
    private static function lineItem(Node $item, string $orderId): array
    {
        $product = Fields::lookupId($item, 'Product_Name');
        $quantity = (int) Decimal::normalise(self::amount($item, 'Quantity') ?? '1');
        $listPrice = self::amount($item, 'List_Price') ?? '0';
        $subtotal = Decimal::multiply($listPrice, $quantity);
        $total = self::amount($item, 'Total') ?? $subtotal;
        $discount = self::amount($item, 'Discount') ?? '0';

        return [
            'gid' => Fields::id('OrderItem', $item->optionalString('id') ?? $orderId . '-' . ($product ?? 'item')),
            'name' => is_array($item->raw()['Product_Name'] ?? null) ? $item->requiredChild('Product_Name')->optionalString('name') ?? '' : '',
            'product_gid' => $product === null ? null : Fields::id('Product', $product),
            'variant_gid' => $product === null ? null : Fields::id('Variant', $product),
            'sku' => null,
            'quantity' => $quantity,
            'subtotal' => $subtotal,
            'total' => Decimal::subtract($total, $discount),
            'taxes' => [],
            'attributes' => array_filter([
                'Line taxes' => implode(', ', array_map(static fn(Node $tax): string => trim(($tax->optionalString('name') ?? '') . ' ' . ($tax->optionalDecimal('percentage') ?? '') . '%'), is_array($item->raw()['Line_Tax'] ?? null) ? $item->list('Line_Tax') : [])),
            ], static fn(string $value): bool => $value !== ''),
        ];
    }

    /** An item amount, whichever case Zoho spells its key in (the reference shows `Quantity` and `quantity`). */
    private static function amount(Node $item, string $field): ?string
    {
        return $item->optionalDecimal($field) ?? $item->optionalDecimal(strtolower($field));
    }
}
