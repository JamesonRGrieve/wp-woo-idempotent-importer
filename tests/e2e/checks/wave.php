<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Wave's end-to-end checks (the shape is described at checks() in assert.php): products, customers and invoices in
 * WooCommerce, customers and their businesses in FluentCRM. The fake API takes a test access token
 * (wp-config-extra.php).
 */

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Fluent\FluentCrm;

/** @return list<string> an order's notes */
$notes = static fn(WC_Order $order): array => array_map(
    static fn(object $note): string => is_string($note->content ?? null) ? $note->content : '',
    wc_get_order_notes(['order_id' => $order->get_id()]),
);
$payments = static fn(WC_Order $order): int => count(array_filter($notes($order), static fn(string $note): bool => str_contains($note, 'Payment of')));

return [
    'v1' => static function () use ($payments): void {
        expect(product('gid://wave/Product/101')->get_regular_price() === '8.5', 'a product at its unit price');
        expect(product('gid://wave/Product/103')->get_status() === 'private' && product('gid://wave/Product/104')->get_status() === 'private', 'a bought-only and an archived product are private');
        $lena = fluentContact('gid://wave/Contact/201');
        expect(FluentCrm::string($lena, 'email') === 'lena@wave.example' && FluentCrm::string($lena, 'company_id') === (string) FluentCrm::id(fluentCompany('gid://wave/Company/201')), "a business customer's contact, in its company");
        $paid = order(Kind::Order, 'gid://wave/Invoice/301');
        expect($paid->get_order_number() === '1001' && money($paid->get_total()) === '218.65' && $paid->get_status() === 'completed', 'a paid invoice with its total');
        expect(money($paid->get_total_tax()) === '18.65' && count($paid->get_taxes()) === 2, 'its taxes, by sales tax');
        expect($paid->get_customer_id() === user('gid://wave/Customer/201')->ID, 'its customer');
        $mugs = array_values($paid->get_items())[0];
        expect($mugs instanceof WC_Order_Item_Product && $mugs->get_product_id() === product('gid://wave/Product/101')->get_id(), 'its items link to their products');
        expect($payments($paid) === 2, 'its two active payments, as notes');
        $guest = order(Kind::Order, 'gid://wave/Invoice/302');
        expect($guest->get_status() === 'pending' && $guest->get_customer_id() === 0 && $guest->get_billing_first_name() === 'Cash Sale', 'an unpaid guest invoice');
    },
    'v2' => static function () use ($payments): void {
        expect(product('gid://wave/Product/101')->get_regular_price() === '9', 'price updated');
        expect(FluentCrm::string(fluentContact('gid://wave/Contact/202'), 'phone') === '+1 250 555 0202', 'a changed phone');
        $settled = order(Kind::Order, 'gid://wave/Invoice/302');
        expect($settled->get_status() === 'completed' && $payments($settled) === 1, 'a payment settles the guest invoice');
        $partial = order(Kind::Order, 'gid://wave/Invoice/303');
        expect($partial->get_status() === 'pending' && $payments($partial) === 1, 'a partly paid invoice is pending, with its payment');
        expect($payments(order(Kind::Order, 'gid://wave/Invoice/301')) === 2, 'payments are not noted twice');
    },
    'intact' => static function (): void {
        expect(FluentCrm::string(fluentContact('gid://wave/Contact/201'), 'email') === 'lena@wave.example', 'the Wave contact is untouched');
        expect(order(Kind::Order, 'gid://wave/Invoice/301')->get_order_number() === '1001', 'the Wave invoice is untouched');
    },
];
