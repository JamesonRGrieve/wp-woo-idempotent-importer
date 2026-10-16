<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Etsy's end-to-end checks (the shape is described at checks() in assert.php).
 */

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Etsy\EtsyAdapter;
use IdempotentImporter\Wp\Options;

return [
    // As if the operator had saved the app and connected the shop.
    'configure' => static function (): void {
        selectSource(EtsyAdapter::ID);
        Credentials::save(EtsyAdapter::REFRESH_TOKEN, 'e2e-refresh-token');
    },
    'v1' => static function (): void {
        $mug = product('gid://etsy/Listing/601');
        expect($mug instanceof WC_Product_Simple && $mug->get_name() === 'Hand-thrown mug "Ocean"', 'the mug is a simple product, entities decoded');
        expect($mug->get_regular_price() === '32' && $mug->get_sku() === 'MUG-OCEAN' && $mug->get_stock_quantity() === 4, 'price, SKU and stock');
        expect($mug->get_weight() === '350', 'weight in the store unit');
        expect($mug->get_image_id() > 0 && count($mug->get_gallery_image_ids()) === 1, 'images by rank: featured + gallery');
        expect(wp_get_post_terms($mug->get_id(), 'product_cat', ['fields' => 'names']) === ['Mugs'], 'section → category');
        expect($mug->get_meta('_mirror_materials') === ['stoneware'], 'materials mirrored as meta');

        $apron = product('gid://etsy/Listing/602');
        expect($apron instanceof WC_Product_Variable && count($apron->get_children()) === 2, 'the apron is variable with 2 variations');
        $charcoal = target(Kind::Variant, 'gid://etsy/ListingVariant/602-1002');
        $charcoalVariation = wc_get_product($charcoal);
        expect($charcoalVariation instanceof WC_Product_Variation && $charcoalVariation->get_stock_quantity() === 0, 'the charcoal variation is out of stock');
        expect(get_post_status(target(Kind::Product, 'gid://etsy/Listing/604')) === 'private', 'an inactive listing is kept, private');
        expect(get_post_status(target(Kind::Product, 'gid://etsy/Listing/603')) === 'draft', 'a draft listing is a draft');

        $order = order(Kind::Order, 'gid://etsy/Receipt/801');
        expect($order->get_status() === 'completed' && $order->get_order_number() === '#801', 'receipt 801 completed, Etsy number kept');
        expect(money($order->get_total()) === '134.40' && money($order->get_total_tax()) === '10.40', 'Etsy total and order-level sales tax');
        $lines = array_values($order->get_items());
        expect(count($lines) === 2 && $lines[1] instanceof WC_Order_Item_Product && $lines[1]->get_variation_id() === $charcoal, 'the apron line points at its variation');
        expect($order->get_customer_id() === user('gid://etsy/User/9001')->ID, 'linked to its buyer');
        expect(count(wc_get_order_notes(['order_id' => $order->get_id()])) >= 1, 'shipment tracking note');
        expect(order(Kind::Order, 'gid://etsy/Receipt/802')->get_status() === 'pending', 'unpaid receipt → pending order');
        expect(product('gid://etsy/Listing/601')->get_stock_quantity() === 4, 'mirroring receipts did not reduce stock');

        $ada = user('gid://etsy/User/9001');
        expect($ada->user_login === 'etsy-9001' && $ada->user_email === 'ada@example.com', 'buyer → customer');

        $reviews = reviews($mug->get_id());
        expect(count($reviews) === 1 && get_comment_meta((int) $reviews[0]->comment_ID, 'rating', true) === '5', 'verified 5-star review on the mug');

        expect(Options::int('wp_page_for_privacy_policy') === target(Kind::Policy, 'gid://etsy/Policy/5001-policy_privacy'), 'Etsy privacy policy page wired');
        expect(get_post_status(target(Kind::Policy, 'gid://etsy/ReturnPolicy/41')) === 'publish', 'return policy page');
    },
    'v2' => static function (): void {
        expect(product('gid://etsy/Listing/601')->get_regular_price() === '34', 'price updated');
        expect(services()->links()->targetId(Kind::Product, 'gid://etsy/Listing/603') === null, 'removed listing unlinked');
        expect(count(get_posts(['post_type' => 'product', 'post_status' => 'trash', 'fields' => 'ids'])) === 2, 'removed listing trashed beside the earlier Shopify deletion');
        $refunded = order(Kind::Order, 'gid://etsy/Receipt/801');
        expect(count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '10.00', 'partial refund mirrored');
        expect(order(Kind::Order, 'gid://etsy/Receipt/803')->get_status() === 'processing', 'new paid receipt → processing order');
        expect(count(reviews(product('gid://etsy/Listing/602')->get_id())) === 1, 'new review on the apron');
    },
    'intact' => static function (): void {
        expect(product('gid://etsy/Listing/601')->get_status() === 'publish', 'the Etsy mug is still published');
        expect(order(Kind::Order, 'gid://etsy/Receipt/801')->get_status() === 'completed', 'the Etsy receipt is untouched');
    },
];
