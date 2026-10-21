<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * An order with its products (`OrderItems`) → a WooCommerce order with Salesforce's total: its order number,
 * effective date (else created date), currency (the order's in a multi-currency org, else the org's default),
 * the contact it is billed to (a WooCommerce customer), billing and shipping addresses and description. A draft
 * order is pending, an activated one processing; the status as Salesforce names it is meta. Each item links to
 * its product with its quantity, unit price and total price. Salesforce orders carry no tax, shipping or
 * discount lines; every other field a user can set (account, contract, purchase order, type) is meta.
 */
final class OrderMapper implements Mapper
{
    private const VERSION = 1;
    private const STATUSES = ['Draft' => 'pending', 'Activated' => 'processing'];
    private const NATIVE = [
        'OrderNumber', 'EffectiveDate', 'CurrencyIsoCode', 'BillToContactId', 'BillingStreet', 'BillingCity', 'BillingState',
        'BillingPostalCode', 'BillingCountry', 'ShippingStreet', 'ShippingCity', 'ShippingState', 'ShippingPostalCode',
        'ShippingCountry', 'Description', 'TotalAmount', 'StatusCode', 'CreatedDate',
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
        $billTo = Fields::text($node, 'BillToContactId');
        $created = Fields::time($node, 'EffectiveDate') ?? Times::requiredIso($node, 'CreatedDate');

        return new Desired(Kind::Order, $node->id(), [
            'name' => $node->string('OrderNumber'),
            'created_at' => $created,
            'currency' => Fields::text($node, 'CurrencyIsoCode') ?? $node->string('org_currency'),
            'prices_include_tax' => false,
            'customer_gid' => $billTo === null ? null : Fields::id('Customer', $billTo),
            'email' => null,
            'phone' => null,
            'billing' => Fields::storeAddress($node, 'Billing'),
            'shipping' => Fields::storeAddress($node, 'Shipping'),
            'coupons' => [],
            'line_items' => array_map(self::lineItem(...), $node->child('OrderItems')?->list('records') ?? []),
            'shipping_lines' => [],
            'tax_lines' => [],
            'discount_total' => '0',
            'shipping_total' => '0',
            'tax_total' => '0',
            'total' => Decimal::of(Fields::text($node, 'TotalAmount') ?? '0'),
            'status' => self::STATUSES[$node->optionalString('StatusCode') ?? ''] ?? 'on-hold',
            'processed_at' => null,
            'closed_at' => null,
            'customer_note' => Fields::text($node, 'Description'),
            'payment_method_title' => null,
            'transaction_id' => null,
            'fees' => [],
            'refunds' => [],
            'notes' => [],
            'original_meta' => [],
            'order' => Fields::details($node, self::NATIVE),
        ]);
    }

    /** @return array<string, mixed> */
    private static function lineItem(Node $item): array
    {
        $product = Fields::text($item, 'Product2Id');
        $total = Decimal::of(Fields::text($item, 'TotalPrice') ?? '0');

        return [
            'gid' => Fields::id('OrderItem', $item->string('Id')),
            'name' => $item->child('Product2')?->optionalString('Name') ?? Fields::text($item, 'Description') ?? '',
            'product_gid' => $product === null ? null : Fields::id('Product2', $product),
            'variant_gid' => $product === null ? null : Fields::id('Variant', $product),
            'sku' => null,
            'quantity' => (int) Decimal::of(Fields::text($item, 'Quantity') ?? '1'),
            'subtotal' => $total,
            'total' => $total,
            'taxes' => [],
            'attributes' => [],
        ];
    }
}
