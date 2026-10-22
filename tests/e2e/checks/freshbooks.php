<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * FreshBooks' end-to-end checks (the shape is described at checks() in assert.php): products, customers and
 * invoices in WooCommerce, clients and their organizations in FluentCRM. The fake API takes a test access token
 * (wp-config-extra.php).
 */

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Fluent\FluentCrm;

/** @return list<string> an order's notes, oldest first */
$notes = static fn(WC_Order $order): array => array_reverse(array_map(
    static fn(object $note): string => is_string($note->content ?? null) ? $note->content : '',
    wc_get_order_notes(['order_id' => $order->get_id()]),
));

return [
    'v1' => static function () use ($notes): void {
        $wash = product('gid://freshbooks/Item/101');
        expect($wash->get_regular_price() === '18' && $wash->get_sku() === 'FB-WASH' && $wash->get_stock_quantity() === 12, 'an item with its price, SKU and inventory');
        expect(product('gid://freshbooks/Item/102')->get_status() === 'private', 'an archived item is private');
        $consultation = product('gid://freshbooks/Service/201');
        expect($consultation->get_regular_price() === '75' && $consultation->is_virtual(), 'a service at its rate, virtual');
        $mira = fluentContact('gid://freshbooks/Contact/301');
        expect(FluentCrm::string($mira, 'email') === 'mira@freshbooks.example' && FluentCrm::string($mira, 'contact_type') === 'customer', 'a client is a FluentCRM customer contact');
        $company = FluentCrm::string($mira, 'company_id');
        expect($company !== '' && $company === FluentCrm::string(fluentContact('gid://freshbooks/Contact/302'), 'company_id'), 'clients naming one organization share its company');
        $paid = order(Kind::Order, 'gid://freshbooks/Invoice/401');
        expect($paid->get_order_number() === '0000101' && money($paid->get_total()) === '142.60' && $paid->get_status() === 'completed', 'a paid invoice, with its amount');
        expect(money($paid->get_total_tax()) === '8.95' && money($paid->get_discount_total()) === '14.85', 'its taxes and discount');
        expect($paid->get_customer_id() === user('gid://freshbooks/Customer/301')->ID, 'its client, a WooCommerce customer');
        expect(count(array_filter($notes($paid), static fn(string $note): bool => str_contains($note, 'Payment of'))) === 2, 'its two payments, as notes');
        expect(order(Kind::Order, 'gid://freshbooks/Invoice/402')->get_status() === 'pending', 'an unpaid invoice is pending payment');
        expect(product('gid://freshbooks/Item/101')->get_stock_quantity() === 12, 'mirroring invoices did not reduce stock');
    },
    'v2' => static function () use ($notes): void {
        $wash = product('gid://freshbooks/Item/101');
        expect($wash->get_regular_price() === '19.5' && $wash->get_stock_quantity() === 10, 'price and inventory updated');
        expect(FluentCrm::string(fluentContact('gid://freshbooks/Contact/301'), 'phone') === '+1 250 555 0399', 'a changed phone');
        $rosa = fluentContact('gid://freshbooks/Contact/304');
        expect(FluentCrm::string($rosa, 'company_id') !== '', 'a new client with a new organization');
        $settled = order(Kind::Order, 'gid://freshbooks/Invoice/402');
        expect($settled->get_status() === 'completed' && count(array_filter($notes($settled), static fn(string $note): bool => str_contains($note, 'by Cash'))) === 1, 'a payment settles an invoice');
        $disputed = order(Kind::Order, 'gid://freshbooks/Invoice/403');
        expect($disputed->get_status() === 'on-hold', 'a disputed invoice is on hold');
        $walkIn = get_user_by('id', $disputed->get_customer_id());
        expect($walkIn instanceof WP_User && str_ends_with($walkIn->user_email, '@freshbooks.invalid') && $disputed->get_billing_first_name() === 'Walk', 'a client without an email is a customer by a placeholder address, and the invoice is theirs');
        expect(count(array_filter($notes(order(Kind::Order, 'gid://freshbooks/Invoice/401')), static fn(string $note): bool => str_contains($note, 'Payment of'))) === 2, 'payments are not noted twice');
        $credited = order(Kind::Order, 'gid://freshbooks/Invoice/404');
        $refunds = $credited->get_refunds();
        expect(count($refunds) === 1 && money($refunds[0]->get_amount()) === '15.00' && $refunds[0]->get_reason() === 'Credit note CN-0001 applied', 'a credit note applied to an invoice is a refund of what was applied, on its order');
        expect(is_array($refunds[0]->get_meta('_mirror_credit_lines')) && $credited->get_status() === 'completed', "with the credit note's lines, the invoice keeps its status");
    },
    'intact' => static function (): void {
        expect(FluentCrm::string(fluentContact('gid://freshbooks/Contact/301'), 'email') === 'mira@freshbooks.example', 'the FreshBooks contact is untouched');
        expect(order(Kind::Order, 'gid://freshbooks/Invoice/401')->get_order_number() === '0000101', 'the FreshBooks invoice is untouched');
    },
];
