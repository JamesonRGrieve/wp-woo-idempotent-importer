<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * QuickBooks Online's end-to-end checks (the shape is described at checks() in assert.php): item categories, items,
 * customers, invoices and sales receipts in WooCommerce, customers and their companies in FluentCRM. The fake API
 * takes a test access token and realm (wp-config-extra.php).
 */

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Fluent\FluentCrm;

$payments = static fn(WC_Order $order): int => count(array_filter(
    array_map(static fn(object $note): string => is_string($note->content ?? null) ? $note->content : '', wc_get_order_notes(['order_id' => $order->get_id()])),
    static fn(string $note): bool => str_contains($note, 'Payment of'),
));

return [
    'v1' => static function () use ($payments): void {
        $celadon = product('gid://quickbooks_online/Item/2');
        expect($celadon->get_regular_price() === '32.5' && $celadon->get_sku() === 'CEL-1' && $celadon->get_stock_quantity() === 20, 'an inventory item with its price, SKU and quantity on hand');
        expect(wp_get_post_terms($celadon->get_id(), 'product_cat', ['fields' => 'names']) === ['Glazes'], 'in its category');
        expect(product('gid://quickbooks_online/Item/3')->is_virtual() && product('gid://quickbooks_online/Item/4')->get_status() === 'private', 'a service is virtual; an inactive item private');
        $maya = fluentContact('gid://quickbooks_online/Contact/10');
        expect(FluentCrm::string($maya, 'email') === 'maya@qbo.example' && FluentCrm::string($maya, 'company_id') === (string) FluentCrm::id(fluentCompany('gid://quickbooks_online/Company/10')), 'a customer is a contact in its company');
        $invoice = order(Kind::Order, 'gid://quickbooks_online/Invoice/130');
        expect($invoice->get_order_number() === '1037' && money($invoice->get_total()) === '152.45' && $invoice->get_status() === 'completed', 'a paid invoice with its total');
        expect(money($invoice->get_total_tax()) === '7.95' && money($invoice->get_discount_total()) === '10.00' && money($invoice->get_shipping_total()) === '12.00', 'its tax, discount and shipping');
        expect(count($invoice->get_items()) === 3 && $invoice->get_customer_id() === user('gid://quickbooks_online/Customer/10')->ID, "its lines (a bundle's own) and customer");
        expect($payments($invoice) === 1, 'its payment, as a note');
        expect(order(Kind::Order, 'gid://quickbooks_online/Invoice/131')->get_status() === 'pending', 'an unpaid invoice is pending payment');
        $receipt = order(Kind::Order, 'gid://quickbooks_online/SalesReceipt/140');
        expect($receipt->get_status() === 'completed' && $receipt->get_customer_id() === 0 && $receipt->get_payment_method_title() === 'Cash', 'a sales receipt to a customer without an email: a paid guest order');
        expect(product('gid://quickbooks_online/Item/2')->get_stock_quantity() === 20, 'mirroring sales did not reduce stock');
    },
    'v2' => static function () use ($payments): void {
        $celadon = product('gid://quickbooks_online/Item/2');
        expect($celadon->get_regular_price() === '34' && $celadon->get_stock_quantity() === 18, 'price and quantity on hand updated');
        expect(FluentCrm::string(fluentContact('gid://quickbooks_online/Contact/11'), 'phone') === '(503) 555-0111', 'a changed phone');
        $settled = order(Kind::Order, 'gid://quickbooks_online/Invoice/131');
        expect($settled->get_status() === 'completed' && $payments($settled) === 1, 'a payment settles the invoice');
        expect($payments(order(Kind::Order, 'gid://quickbooks_online/Invoice/130')) === 1, 'payments are not noted twice');
    },
    'intact' => static function (): void {
        expect(FluentCrm::string(fluentContact('gid://quickbooks_online/Contact/10'), 'email') === 'maya@qbo.example', 'the QuickBooks contact is untouched');
        expect(order(Kind::Order, 'gid://quickbooks_online/Invoice/130')->get_order_number() === '1037', 'the QuickBooks invoice is untouched');
    },
];
