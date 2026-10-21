<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Pipedrive's end-to-end checks (the shape is described at checks() in assert.php): CRM data in FluentCRM, the
 * product catalogue in WooCommerce. The fake company takes an API token (wp-config-extra.php).
 */

use IdempotentImporter\Wp\Fluent\FluentCrm;

$adaGid = 'gid://pipedrive/Person/201';

return [
    'v1' => static function () use ($adaGid): void {
        $ada = fluentContact($adaGid);
        expect(FluentCrm::string($ada, 'email') === 'ada.thorne@pipedrive.example' && FluentCrm::string($ada, 'phone') === '+1 250 555 0111', 'a person is a FluentCRM contact, by its primary email and phone');
        expect(fluentTags($ada) === ['Studio member', 'Wholesale'] && fluentLists($ada) === ['Glaze lovers'], 'its labels are tags, its filter a list');
        expect(FluentCrm::string($ada, 'company_id') === (string) FluentCrm::id(fluentCompany('gid://pipedrive/Organization/101')), 'its organization is its company');
        expect(fluentField($ada, 'pipedrive_8c2d9f1a0b3e4f5a6b7c8d9e0f1a2b3c4d5e6f70') === 'Celadon' && fluentField($ada, 'pipedrive_1a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d') === '1200.5 CAD', 'custom person fields');
        expect(fluentNoteTypes($ada) === ['call', 'note'], 'its note and its done call are FluentCRM notes');
        expect(fluentNoteTypes(fluentContact('gid://pipedrive/Person/202')) === ['call'], "the call is on its other participant's profile too");
        expect(fluentCompanyField(fluentCompany('gid://pipedrive/Organization/101'), 'pipedrive_f0e1d2c3b4a5968778695a4b3c2d1e0f9a8b7c6d') === 'Gold', 'custom organization fields');
        $clay = product('gid://pipedrive/Product/301');
        expect($clay->get_regular_price() === '42.5' && $clay->get_sku() === 'PD-CLAY', 'a product at its price in the default currency');
        expect(wp_get_post_terms($clay->get_id(), 'product_cat', ['fields' => 'names']) === ['Clay'], 'its category');
        $class = product('gid://pipedrive/Product/302');
        expect($class instanceof WC_Product_Variable && count($class->get_children()) === 2, 'a product with variations is variable');
    },
    'v2' => static function () use ($adaGid): void {
        expect(fluentTags(fluentContact('gid://pipedrive/Person/202')) === [] && fluentLists(fluentContact('gid://pipedrive/Person/202')) === [], 'a removed label and a filter left');
        $cy = fluentContact('gid://pipedrive/Person/204');
        expect(fluentLists($cy) === ['Glaze lovers'] && fluentTags($cy) === ['Studio member'], 'a new person, in the filter, with its label');
        expect(fluentCompanyField(fluentCompany('gid://pipedrive/Organization/101'), 'pipedrive_f0e1d2c3b4a5968778695a4b3c2d1e0f9a8b7c6d') === 'Silver', 'a changed organization field');
        expect(fluentNoteTypes(fluentContact($adaGid)) === ['call', 'note'], 'a changed note is updated, not added again');
        expect(product('gid://pipedrive/Product/301')->get_regular_price() === '44', 'price updated');
    },
    'intact' => static function () use ($adaGid): void {
        expect(FluentCrm::string(fluentContact($adaGid), 'email') === 'ada.thorne@pipedrive.example', 'the Pipedrive person is untouched');
        expect(product('gid://pipedrive/Product/301')->get_status() === 'publish', 'the Pipedrive product is untouched');
    },
];
