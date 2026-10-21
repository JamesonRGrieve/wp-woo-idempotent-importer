<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * HubSpot's end-to-end checks (the shape is described at checks() in assert.php): CRM data in FluentCRM, store
 * data in WooCommerce. The fake account has a private app token (wp-config-extra.php).
 */

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Fluent\FluentCrm;

$adaGid = 'gid://hubspot/Contact/501';

return [
    'v1' => static function () use ($adaGid): void {
        $ada = fluentContact($adaGid);
        expect(FluentCrm::string($ada, 'email') === 'ada.thorne@example.com' && FluentCrm::string($ada, 'first_name') === 'Ada' && FluentCrm::string($ada, 'last_name') === 'Thorne', 'a HubSpot contact is a FluentCRM contact');
        expect(FluentCrm::string($ada, 'status') === 'transactional' && FluentCrm::string($ada, 'contact_type') === 'customer' && FluentCrm::string($ada, 'source') === 'HubSpot', 'transactional (no recorded consent), a customer, from HubSpot');
        expect(FluentCrm::string($ada, 'city') === 'Victoria' && FluentCrm::string($ada, 'postal_code') === 'V8V 1A1', 'its address');
        expect(fluentLists($ada) === ['Glaze lovers', 'Studio members'], 'its lists and segments');
        $fernhill = fluentCompany('gid://hubspot/Company/9001');
        expect(FluentCrm::string($ada, 'company_id') === (string) FluentCrm::id($fernhill), 'its first company is its primary company');
        expect(count(FluentCrm::memberIds($ada, FluentCrm::COMPANY_TYPE)) === 2, 'and it belongs to both its companies');
        expect(fluentField($ada, 'hubspot_favorite_glaze') === 'Celadon' && fluentField($ada, 'hubspot_kiln_sizes') === ['Small', 'Large'], 'custom properties are FluentCRM custom fields');
        expect(FluentCrm::string($fernhill, 'website') === 'https://fernhill.example', 'a HubSpot company is a FluentCRM company');
        expect(fluentCompanyField($fernhill, 'hubspot_wholesale_tier') === 'Gold', 'with its custom company fields');
        expect(fluentNoteTypes($ada) === ['call', 'email', 'note'], 'its note, call and email are FluentCRM notes of their types');
        expect(services()->links()->targetId(Kind::Contact, 'gid://hubspot/Contact/504') === null, 'a contact without an email is not a FluentCRM contact');

        $clay = product('gid://hubspot/Product/7001');
        expect($clay instanceof WC_Product_Simple && $clay->get_regular_price() === '42.5' && $clay->get_sku() === 'HS-CLAY-10', 'a library product is a WooCommerce product');
        $order = order(Kind::Order, 'gid://hubspot/Order/6001');
        expect($order->get_order_number() === 'HS-1001' && money($order->get_total()) === '100.00' && $order->get_currency() === 'CAD', 'a commerce order is a WooCommerce order, totalled from its line items');
        $buyer = user('gid://hubspot/Customer/501');
        expect($order->get_customer_id() === $buyer->ID && $buyer->user_email === 'ada.thorne@example.com', 'placed by its contact, a WooCommerce customer');
        $lines = array_values($order->get_items());
        expect(count($lines) === 2 && $lines[0] instanceof WC_Order_Item_Product && $lines[0]->get_product_id() === $clay->get_id(), 'its line item points at its product');
    },
    'v2' => static function () use ($adaGid): void {
        $ada = fluentContact($adaGid);
        expect(fluentField($ada, 'hubspot_studio_notes') === 'Prefers evening firings.', 'a changed custom property is updated');
        expect(fluentCompanyField(fluentCompany('gid://hubspot/Company/9001'), 'hubspot_wholesale_tier') === 'Silver', 'a changed company field is updated');
        $cy = fluentContact('gid://hubspot/Contact/503');
        expect(fluentLists($cy) === ['Glaze lovers'] && fluentNoteTypes($cy) === ['note'], 'a new contact joins the segment, with its note');
        expect(fluentLists(fluentContact('gid://hubspot/Contact/502')) === [], 'a contact who left the segment leaves the list');
        expect(product('gid://hubspot/Product/7001')->get_regular_price() === '44', 'price updated');
        $new = order(Kind::Order, 'gid://hubspot/Order/6002');
        expect($new->get_customer_id() === user('gid://hubspot/Customer/503')->ID && money($new->get_total()) === '160.00', 'a new order by a new customer');
    },
    'intact' => static function () use ($adaGid): void {
        expect(FluentCrm::string(fluentContact($adaGid), 'email') === 'ada.thorne@example.com', 'the HubSpot contact is untouched');
        expect(order(Kind::Order, 'gid://hubspot/Order/6001')->get_order_number() === 'HS-1001', 'the HubSpot order is untouched');
    },
];
