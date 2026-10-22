<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Salesforce's end-to-end checks (the shape is described at checks() in assert.php): CRM data in FluentCRM, store
 * data in WooCommerce. The fake org takes an access token and names its instance URL (wp-config-extra.php).
 */

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Fluent\FluentBoards;
use IdempotentImporter\Wp\Fluent\FluentCrm;

$adaGid = 'gid://salesforce/Contact/003Fh00000Ada001AAA';

return [
    'v1' => static function () use ($adaGid): void {
        $ada = fluentContact($adaGid);
        expect(FluentCrm::string($ada, 'email') === 'ada@fernhill.example' && FluentCrm::string($ada, 'prefix') === 'Ms.' && FluentCrm::string($ada, 'date_of_birth') === '1988-04-12', 'a contact is a FluentCRM contact, with salutation and birthdate');
        expect(FluentCrm::string($ada, 'status') === 'transactional' && FluentCrm::string($ada, 'source') === 'Salesforce', 'transactional (no recorded opt-in), from Salesforce');
        expect(FluentCrm::string(fluentContact('gid://salesforce/Contact/003Fh00000Bo0001AAA'), 'status') === 'unsubscribed', 'opted out of email → unsubscribed');
        expect(fluentLists($ada) === ['Autumn market', 'Studio members'] && fluentTags($ada) === ['Wholesale'], 'its campaigns are lists, its topics tags');
        $fernhill = fluentCompany('gid://salesforce/Account/001Fh00000Fern01AAA');
        expect(FluentCrm::string($ada, 'company_id') === (string) FluentCrm::id($fernhill), 'its account is its company');
        expect(fluentCompanyField($fernhill, 'salesforce_wholesale_tier_c') === 'Gold' && FluentCrm::string($fernhill, 'address_line_2') === 'Unit 2', 'custom account fields, and the street\'s second line');
        expect(fluentField($ada, 'salesforce_kiln_sizes_c') === ['Small', 'Large'] && fluentField($ada, 'salesforce_wholesale_c') === 'Yes', 'custom contact fields');
        expect(fluentNoteTypes($ada) === ['call', 'note'], 'its logged call and note are FluentCRM notes');
        expect(FluentCrm::string(fluentContact('gid://salesforce/Contact/003Fh00000NoMl01AAA'), 'email') === 'record-003fh00000noml01aaa@salesforce.invalid', 'a contact without an email is a FluentCRM contact by a placeholder');

        $clay = product('gid://salesforce/Product2/01tFh00000Clay1AAA');
        expect($clay->get_regular_price() === '42.5' && $clay->get_sku() === 'SF-CLAY-10', 'a product at its standard price');
        expect(product('gid://salesforce/Product2/01tFh00000Clss1AAA')->get_status() === 'private', 'an inactive product is private');
        $order = order(Kind::Order, 'gid://salesforce/Order/801Fh00000Ord01AAA');
        expect($order->get_order_number() === '00000101' && money($order->get_total()) === '100.00' && $order->get_status() === 'processing', 'an activated order, with its total');
        expect($order->get_customer_id() === user('gid://salesforce/Customer/003Fh00000Ada001AAA')->ID, 'billed to its contact, a WooCommerce customer');

        $opportunities = board('gid://salesforce/Board/Opportunity');
        expect(stageTitles($opportunities) === ['Prospecting', 'Negotiation', 'Closed Won', 'Closed Lost'] && FluentBoards::string($opportunities, 'currency') === 'CAD', 'the opportunity stages are a FluentBoards board, in the org currency');
        $mugs = boardTask(Kind::CrmDeal, 'gid://salesforce/Opportunity/006Fh00000Mugs1AAA');
        expect(stageTitle($mugs) === 'Negotiation' && FluentBoards::string($mugs, 'lead_value') === '1500.50' && FluentBoards::string($mugs, 'due_at') === '2026-10-15 00:00:00', 'an opportunity is a task in its stage, with its amount and close date');
        expect(FluentBoards::string($mugs, 'crm_contact_id') === (string) FluentCrm::id($ada) && assignees($mugs) === [1], 'linked to its primary contact, assigned to its owner');
        expect(assignees(boardTask(Kind::CrmDeal, 'gid://salesforce/Opportunity/006Fh00000Kiln1AAA')) === [], 'an owner with no WordPress user leaves it unassigned');
        $visit = boardTask(Kind::CrmTask, 'gid://salesforce/Task/00TFh00000Todo1AAA');
        expect(stageTitle($visit) === 'Not Started' && FluentBoards::string($visit, 'priority') === 'high', 'a task is a FluentBoards task in the stage of its status');
    },
    'v2' => static function () use ($adaGid): void {
        expect(fluentCompanyField(fluentCompany('gid://salesforce/Account/001Fh00000Fern01AAA'), 'salesforce_wholesale_tier_c') === 'Silver', 'a changed custom account field');
        $cy = fluentContact('gid://salesforce/Lead/00QFh00000Cy0001AAA');
        expect(FluentCrm::string($cy, 'city') === 'Montréal' && fluentLists($cy) === ['Autumn market'] && fluentNoteTypes($cy) === ['note'], 'a new lead, a campaign member, with its note');
        expect(fluentNoteTypes(fluentContact($adaGid)) === ['call', 'note'], 'a changed note is updated, not added again');
        expect(product('gid://salesforce/Product2/01tFh00000Clay1AAA')->get_regular_price() === '44', 'price updated');
        $draft = order(Kind::Order, 'gid://salesforce/Order/801Fh00000Ord02AAA');
        expect($draft->get_status() === 'pending' && $draft->get_customer_id() === user('gid://salesforce/Customer/003Fh00000Bo0001AAA')->ID, 'a new draft order for a new customer');

        $mugs = boardTask(Kind::CrmDeal, 'gid://salesforce/Opportunity/006Fh00000Mugs1AAA');
        expect(stageTitle($mugs) === 'Closed Won' && FluentBoards::string($mugs, 'status') === 'closed', 'a won opportunity is closed in its stage');
        expect(stageTitle(boardTask(Kind::CrmDeal, 'gid://salesforce/Opportunity/006Fh00000Work1AAA')) === 'Prospecting', 'a new opportunity');
        expect(FluentBoards::string(boardTask(Kind::CrmTask, 'gid://salesforce/Task/00TFh00000Todo1AAA'), 'status') === 'closed', 'a completed task is closed');
        expect(FluentBoards::string(boardTask(Kind::CrmTask, 'gid://salesforce/Task/00TFh00000Todo2AAA'), 'crm_contact_id') === (string) FluentCrm::id($cy), "a lead's task is linked to the lead's contact");
    },
    'intact' => static function () use ($adaGid): void {
        expect(FluentCrm::string(fluentContact($adaGid), 'email') === 'ada@fernhill.example', 'the Salesforce contact is untouched');
        expect(order(Kind::Order, 'gid://salesforce/Order/801Fh00000Ord01AAA')->get_order_number() === '00000101', 'the Salesforce order is untouched');
        expect(FluentBoards::string(boardTask(Kind::CrmDeal, 'gid://salesforce/Opportunity/006Fh00000Mugs1AAA'), 'archived_at') === '', 'the Salesforce opportunity is untouched');
    },
];
