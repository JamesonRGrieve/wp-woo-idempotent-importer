<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Zoho CRM's end-to-end checks (the shape is described at checks() in assert.php): CRM data in FluentCRM, store
 * data in WooCommerce. The fake org takes a test access token on a test API domain (wp-config-extra.php).
 */

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Zoho\Mapping\Fields;
use IdempotentImporter\Wp\Fluent\FluentBoards;
use IdempotentImporter\Wp\Fluent\FluentCrm;

$adaGid = 'gid://zoho/Contact/5725767000011356001';

return [
    'v1' => static function () use ($adaGid): void {
        $ada = fluentContact($adaGid);
        expect(FluentCrm::string($ada, 'email') === 'ada@zoho.example' && FluentCrm::string($ada, 'source') === 'Zoho CRM', 'a contact is a FluentCRM contact');
        expect(fluentTags($ada) === ['Wholesale'] && fluentLists($ada) === ['Autumn market'], 'its tags and campaigns');
        expect(FluentCrm::string($ada, 'company_id') === (string) FluentCrm::id(fluentCompany('gid://zoho/Account/5725767000011373001')), 'its account is its company');
        expect(fluentField($ada, 'zoho_kiln_sizes') === ['Small', 'Large'] && fluentField($ada, 'zoho_wholesale') === 'Yes', 'custom contact fields');
        expect(fluentNoteTypes($ada) === ['call', 'note'], 'its note and call');
        expect(FluentCrm::string(fluentContact('gid://zoho/Contact/5725767000011356002'), 'status') === 'unsubscribed', 'Email Opt Out → unsubscribed');
        $clay = product('gid://zoho/Product/5725767000011358001');
        expect($clay->get_regular_price() === '42.5' && $clay->get_stock_quantity() === 30 && $clay->get_sku() === 'ZH-CLAY', 'a product with its price and stock in hand');
        expect(wp_get_post_terms($clay->get_id(), 'product_cat', ['fields' => 'names']) === ['Clay'], 'its category');
        $order = order(Kind::Order, 'gid://zoho/SalesOrder/5725767000011355001');
        expect($order->get_order_number() === 'SO-00101' && money($order->get_total()) === '101.00' && $order->get_status() === 'completed', 'a delivered sales order, with its grand total');
        expect(money($order->get_total_tax()) === '4.75' && money($order->get_discount_total()) === '5.00', 'its tax and discount');
        expect($order->get_customer_id() === user('gid://zoho/Customer/5725767000011356001')->ID, 'its contact, a WooCommerce customer');
        expect(product('gid://zoho/Product/5725767000011358001')->get_stock_quantity() === 30, 'mirroring orders did not reduce stock');
        expect(term(Kind::Collection, Fields::categoryId('Glaze Kits'), 'product_cat')->name === 'Glaze kits', 'a category picklist value');

        expect(stageTitles(board('gid://zoho/Pipeline/5725767000000135001')) === ['Qualification', 'Negotiation', 'Closed Won', 'Closed Lost'], 'a deal pipeline is a FluentBoards board with its stages');
        $mugs = boardTask(Kind::CrmDeal, 'gid://zoho/Deal/5725767000011390001');
        expect(stageTitle($mugs) === 'Negotiation' && FluentBoards::string($mugs, 'lead_value') === '1500.50' && FluentBoards::string($mugs, 'crm_contact_id') === (string) FluentCrm::id($ada), 'a deal is a task in its stage, with its amount and contact');
        expect(assignees($mugs) === [1] && assignees(boardTask(Kind::CrmDeal, 'gid://zoho/Deal/5725767000011390002')) === [], 'assigned to its owner only on a clean email match');
        expect(stageTitle(boardTask(Kind::CrmTask, 'gid://zoho/Task/5725767000011391001')) === 'Not Started', 'a task is a FluentBoards task in the stage of its status');
    },
    'v2' => static function () use ($adaGid): void {
        expect(fluentCompanyField(fluentCompany('gid://zoho/Account/5725767000011373001'), 'zoho_wholesale_tier') === 'Silver', 'a changed custom account field');
        $cy = fluentContact('gid://zoho/Lead/5725767000011357001');
        expect(fluentLists($cy) === ['Autumn market'] && fluentNoteTypes($cy) === ['note'], 'a new lead in the campaign, with its note');
        expect(fluentNoteTypes(fluentContact($adaGid)) === ['call', 'note'], 'a changed note is updated, not added again');
        expect(product('gid://zoho/Product/5725767000011358001')->get_regular_price() === '44', 'price updated');
        $new = order(Kind::Order, 'gid://zoho/SalesOrder/5725767000011355002');
        expect($new->get_status() === 'processing' && $new->get_customer_id() === user('gid://zoho/Customer/5725767000011356002')->ID, 'a new sales order for a new customer');

        $mugs = boardTask(Kind::CrmDeal, 'gid://zoho/Deal/5725767000011390001');
        expect(stageTitle($mugs) === 'Closed Won' && FluentBoards::string($mugs, 'status') === 'closed', 'a won deal is closed in its stage');
        expect(FluentBoards::string(boardTask(Kind::CrmTask, 'gid://zoho/Task/5725767000011391001'), 'status') === 'closed', 'a completed task is closed');
    },
    'intact' => static function () use ($adaGid): void {
        expect(FluentCrm::string(fluentContact($adaGid), 'email') === 'ada@zoho.example', 'the Zoho contact is untouched');
        expect(order(Kind::Order, 'gid://zoho/SalesOrder/5725767000011355001')->get_order_number() === 'SO-00101', 'the Zoho order is untouched');
    },
];
