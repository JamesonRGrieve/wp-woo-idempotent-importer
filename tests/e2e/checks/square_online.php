<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Square Online's end-to-end checks (the shape is described at checks() in assert.php).
 */

use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Storefront;

return [
    'v1' => static function (): void {
        $mug = product('gid://square_online/Item/ITEM_MUG');
        expect($mug instanceof WC_Product_Simple && $mug->get_name() === 'Ocean mug', 'the mug is a simple product');
        expect($mug->get_regular_price() === '32' && $mug->get_sku() === 'PP-MUG-OCEAN' && $mug->get_stock_quantity() === 4, 'price, SKU and main-location stock');
        expect($mug->get_global_unique_id() === '0629104150021', 'UPC → GTIN');
        expect($mug->get_image_id() > 0 && count($mug->get_gallery_image_ids()) === 1, 'images in order: featured + gallery');
        expect(wp_get_post_terms($mug->get_id(), 'product_cat', ['fields' => 'names']) === ['Mugs'], 'category → product category');
        expect($mug->get_meta('_mirror_square_product_type') === 'REGULAR', 'Square product type mirrored as meta');
        expect(get_term_meta(target(Kind::Collection, 'gid://square_online/Category/CAT_MUGS'), 'thumbnail_id', true) !== '', 'category image');

        $apron = product('gid://square_online/Item/ITEM_APRON');
        expect($apron instanceof WC_Product_Variable && count($apron->get_children()) === 2, 'the apron is variable with 2 variations');
        $charcoal = target(Kind::Variant, 'gid://square_online/ItemVariation/VAR_APRON_CHAR');
        $charcoalVariation = wc_get_product($charcoal);
        expect($charcoalVariation instanceof WC_Product_Variation && $charcoalVariation->get_regular_price() === '48', "the main location's price override");
        expect($charcoalVariation instanceof WC_Product_Variation && $charcoalVariation->get_stock_quantity() === 0, 'sold out at the main location → no stock');
        expect(get_post_status(target(Kind::Product, 'gid://square_online/Item/ITEM_SAMPLE')) === 'private', 'an archived item is kept, private');
        expect(services()->links()->targetId(Kind::Product, 'gid://square_online/Item/ITEM_GIFT') === null, 'the gift card is not mirrored');

        $order = order(Kind::Order, 'gid://square_online/Order/ORDER_1');
        expect($order->get_status() === 'completed' && $order->get_order_number() === '#ORDER_1', 'order 1 completed, Square id kept as its number');
        expect(money($order->get_total()) === '123.06' && money($order->get_total_tax()) === '5.86', "Square's total and tax");
        expect(money($order->get_shipping_total()) === '10.00' && money($order->get_discount_total()) === '4.80', 'shipping service charge and discount');
        $lines = array_values($order->get_items());
        expect(count($lines) === 2 && $lines[1] instanceof WC_Order_Item_Product && $lines[1]->get_variation_id() === $charcoal, 'the apron line points at its variation');
        expect($order->get_customer_id() === user('gid://square_online/Customer/CUST_ADA')->ID, 'linked to its customer');
        expect(count(wc_get_order_notes(['order_id' => $order->get_id()])) >= 1, 'fulfillment note');
        expect(order(Kind::Order, 'gid://square_online/Order/ORDER_2')->get_status() === 'pending', 'open unpaid order → pending');
        expect(product('gid://square_online/Item/ITEM_MUG')->get_stock_quantity() === 4, 'mirroring orders did not reduce stock');

        $ada = user('gid://square_online/Customer/CUST_ADA');
        expect($ada->user_email === 'ada@example.com' && get_user_meta($ada->ID, 'billing_postcode', true) === 'T5J 1A1', 'customer, email and address');

        expect(get_post_status(target(Kind::Policy, 'gid://square_online/ReturnPolicy/LOCMAIN1')) === 'publish', 'return policy page');
        expect(get_option('timezone_string') === 'America/Edmonton', "the main location's timezone");
        $storefront = new Storefront(services()->settings(), services()->redirects(), services()->links());
        expect($storefront->target(LegacyPaths::normalise('/product/ocean-mug/ITEM_MUG/')) === get_permalink($mug->get_id()), 'old Square Online product URL → product');
    },
    'v2' => static function (): void {
        expect(product('gid://square_online/Item/ITEM_MUG')->get_regular_price() === '34', 'price updated');
        expect(services()->links()->targetId(Kind::Product, 'gid://square_online/Item/ITEM_SAMPLE') === null, 'deleted item unlinked');
        expect(trashedProducts() === 3, 'deleted item trashed beside the earlier Shopify and Etsy deletions');
        $refunded = order(Kind::Order, 'gid://square_online/Order/ORDER_1');
        expect(count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '10.00', 'partial refund mirrored');
        $new = order(Kind::Order, 'gid://square_online/Order/ORDER_3');
        expect($new->get_status() === 'processing' && $new->get_customer_id() === user('gid://square_online/Customer/CUST_GRACE')->ID, 'new paid order → processing, linked to the new customer');
    },
    'intact' => static function (): void {
        expect(product('gid://square_online/Item/ITEM_MUG')->get_status() === 'publish', 'the Square mug is still published');
        expect(order(Kind::Order, 'gid://square_online/Order/ORDER_1')->get_status() === 'completed', 'the Square order is untouched');
    },
];
