<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Wix's end-to-end checks (the shape is described at checks() in assert.php). The fake site runs a V3
 * Stores catalog; the V1 catalog is covered by the unit tests.
 */

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Wix\WixAdapter;
use IdempotentImporter\Wp\Options;
use IdempotentImporter\Wp\Storefront;

$mugGid = 'gid://wix/Product/1a7e5c10-0001-4c2e-9b41-6f0d2a1e0001';
$towelGid = 'gid://wix/Product/1a7e5c10-0002-4c2e-9b41-6f0d2a1e0002';
$orderGid = 'gid://wix/Order/8f1e2d30-0001-4c5b-a69d-7e8f9a0b0001';
$mayaGid = 'gid://wix/Contact/2c4e6a80-0001-4b3d-9e1f-5a6b7c8d0001';
$loyaltyGid = 'gid://wix/LoyaltyAccount/5b0e7c40-0001-4a1b-8c2d-3e4f5a6b0001';

return [
    // Wix, with its loyalty accounts mirrored into Square Loyalty (the fake Square in wp-config).
    'configure' => static function (): void {
        selectSource(WixAdapter::ID);
        update_option(Settings::OPTION, [...Options::array(Settings::OPTION), 'kinds' => [...Settings::DEFAULTS['kinds'], Kind::LoyaltyAccount->value]], false);
    },
    'v1' => static function () use ($mugGid, $towelGid, $orderGid, $mayaGid, $loyaltyGid): void {
        $loyalty = services()->giftCards()->find($loyaltyGid) ?? throw new RuntimeException('FAILED: the loyalty account was not mirrored');
        expect($loyalty->squareId === 'LA_MAYA' && $loyalty->sourceBalance === '120' && $loyalty->loadedBalance === '120', "the Wix loyalty account is a Square Loyalty account, enrolled by the customer's phone, with its points");
        expect($loyalty->userId === user($mayaGid)->ID, "and it is the customer's");
        $mug = product($mugGid);
        expect($mug instanceof WC_Product_Simple && $mug->get_name() === 'Speckled clay mug', 'the mug is a simple product (its one variant)');
        expect($mug->get_regular_price() === '28' && $mug->get_sku() === 'WIX-MUG-1' && $mug->get_stock_quantity() === 14, 'price, SKU and its inventory item\'s quantity');
        expect($mug->get_global_unique_id() === '0845511200019', 'barcode → GTIN');
        expect($mug->get_weight() === '0.45', 'weight in the product\'s unit');
        expect($mug->get_image_id() > 0 && count($mug->get_gallery_image_ids()) === 1, 'main media featured, the other in the gallery');
        expect(wp_get_post_terms($mug->get_id(), 'product_cat', ['fields' => 'names']) === ['Mugs'], 'category → product category');
        expect(wp_get_post_terms($mug->get_id(), 'product_brand', ['fields' => 'names']) === ['Prairie Pottery'], 'brand → WooCommerce brand');
        expect(get_term_meta(target(Kind::Collection, 'gid://wix/Category/3b9f2a40-0001-4d6c-8e1f-2a3b4c5d0001'), 'thumbnail_id', true) !== '', 'category image');

        $towel = product($towelGid);
        expect($towel instanceof WC_Product_Variable && count($towel->get_children()) === 2, 'the towel is variable with 2 variations');
        $sageId = target(Kind::Variant, 'gid://wix/Variant/1a7e5c10-0002-4c2e-9b41-6f0d2a1e0002:7c1d3e20-0001-4f8a-a3b2-1d9c4e5f0001');
        $sage = wc_get_product($sageId);
        expect($sage instanceof WC_Product_Variation && $sage->get_regular_price() === '22' && $sage->get_sale_price() === '18' && $sage->get_stock_quantity() === 6, 'compare-at price regular, selling price on sale, stock');
        $rust = wc_get_product(target(Kind::Variant, 'gid://wix/Variant/1a7e5c10-0002-4c2e-9b41-6f0d2a1e0002:7c1d3e20-0002-4f8a-a3b2-1d9c4e5f0002'));
        expect($rust instanceof WC_Product_Variation && !$rust->get_manage_stock(), 'a variant tracked by status is untracked');
        expect(get_post_status(target(Kind::Product, 'gid://wix/Product/1a7e5c10-0003-4c2e-9b41-6f0d2a1e0003')) === 'private', 'a hidden product is kept, private');

        $order = order(Kind::Order, $orderGid);
        expect($order->get_status() === 'completed' && $order->get_order_number() === '#10001', 'paid and fulfilled → completed, Wix number kept');
        expect(money($order->get_total()) === '69.93' && money($order->get_total_tax()) === '3.33', "Wix's total and tax");
        expect(money($order->get_shipping_total()) === '9.00' && money($order->get_discount_total()) === '6.40', 'shipping and coupon discount');
        $lines = array_values($order->get_items());
        expect(count($lines) === 2 && $lines[0] instanceof WC_Order_Item_Product && $lines[0]->get_variation_id() === $sageId, 'the towel line points at its variation');
        expect($lines[1] instanceof WC_Order_Item_Product && $lines[1]->get_product_id() === $mug->get_id(), 'the mug line points at the simple product');
        expect(count($order->get_coupon_codes()) === 1, 'the coupon code');
        expect($order->get_customer_id() === user($mayaGid)->ID, 'linked to its contact');
        expect($order->get_transaction_id() === 'pi_wix_0001', 'the payment transaction');
        expect(count(wc_get_order_notes(['order_id' => $order->get_id()])) >= 1, 'fulfillment note');
        expect(order(Kind::Order, 'gid://wix/Order/8f1e2d30-0002-4c5b-a69d-7e8f9a0b0002')->get_status() === 'on-hold', 'an unpaid offline order is on hold');
        expect(product($mugGid)->get_stock_quantity() === 14, 'mirroring orders did not reduce stock');

        $maya = user($mayaGid);
        expect($maya->user_login === 'wix-2c4e6a80-0001-4b3d-9e1f-5a6b7c8d0001' && $maya->user_email === 'maya.thunderchild@example.com', 'contact and email');
        expect(get_user_meta($maya->ID, 'billing_postcode', true) === 'S4R 2R4' && get_user_meta($maya->ID, 'shipping_state', true) === 'SK', 'tagged addresses → billing and shipping');

        $welcome = new WC_Coupon('WIX-WELCOME10');
        expect($welcome->get_discount_type() === 'percent' && money($welcome->get_amount()) === '10.00' && money($welcome->get_minimum_amount()) === '40.00', 'percentage off with a minimum subtotal');
        expect((new WC_Coupon('WIX-SHIPFREE'))->get_free_shipping(), 'free-shipping coupon');
        expect((new WC_Coupon('WIX-TOWEL15'))->get_product_ids() === [$towel->get_id()], 'a percentage off one product');
        expect(wc_get_coupon_id_by_code('WIX-MUG5') === 0 && wc_get_coupon_id_by_code('WIX-OLD') === 0, 'an amount off one product and a deactivated coupon are not mirrored');

        $post = target(Kind::Article, 'gid://wix/Post/0e1f2a30-0001-4b5c-9d6e-7f8a9b0c0001');
        expect(has_category(target(Kind::Blog, 'gid://wix/BlogCategory/4d3c2b10-0001-4e5f-8a9b-0c1d2e3f0001'), $post), 'post in its blog category');
        $content = get_post($post)->post_content ?? '';
        expect(str_contains($content, '<strong>every five minutes</strong>'), 'rich content as HTML');
        expect(get_post_thumbnail_id($post) > 0, 'cover image featured');

        expect(count(reviews($mug->get_id())) === 1, 'approved review on the mug');
        expect(count(reviews($towel->get_id())) === 0, 'a review in moderation is not approved');

        $storefront = new Storefront(services()->settings(), services()->redirects(), services()->links());
        expect($storefront->target(LegacyPaths::normalise('/product-page/speckled-clay-mug')) === get_permalink($mug->get_id()), 'old product page → product');
        expect($storefront->target(LegacyPaths::normalise('/old-mug')) === get_permalink($mug->get_id()), 'Wix redirect to a product page → the mirrored product');
        expect($storefront->target(LegacyPaths::normalise('/shop-sale')) === 'https://sale.prairiepottery.example/', 'redirect to a URL');
        expect($storefront->target(LegacyPaths::normalise('/post/firing-the-anagama')) === get_permalink($post), 'old post URL → post');
    },
    'v2' => static function () use ($mugGid, $orderGid, $loyaltyGid): void {
        $loyalty = services()->giftCards()->find($loyaltyGid) ?? throw new RuntimeException('FAILED: the loyalty account was not mirrored');
        expect($loyalty->sourceBalance === '70' && $loyalty->loadedBalance === '70', 'points redeemed at Wix are taken from the Square account');
        $kinds = array_map(static fn($entry): string => $entry->kind, services()->giftCards()->ledger($loyalty->id));
        expect($kinds === ['earn', 'adjust', 'redeem', 'adjust_points', 'adjust_points'], 'the ledger: the Wix history, then what was brought into Square');
        expect(product($mugGid)->get_regular_price() === '30', 'price updated');
        expect(services()->links()->targetId(Kind::Product, 'gid://wix/Product/1a7e5c10-0003-4c2e-9b41-6f0d2a1e0003') === null, 'deleted product unlinked');
        expect(trashedProducts() === 6, 'deleted product trashed beside the earlier deletions');
        $refunded = order(Kind::Order, $orderGid);
        expect($refunded->get_status() === 'completed' && count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '17.01', 'partial refund mirrored, the failed one not');
        $new = order(Kind::Order, 'gid://wix/Order/8f1e2d30-0003-4c5b-a69d-7e8f9a0b0003');
        expect($new->get_status() === 'processing' && $new->get_customer_id() === user('gid://wix/Contact/2c4e6a80-0003-4b3d-9e1f-5a6b7c8d0003')->ID, 'new paid order → processing, linked to the new contact');
        $post = target(Kind::Article, 'gid://wix/Post/0e1f2a30-0002-4b5c-9d6e-7f8a9b0c0002');
        expect(has_category(target(Kind::Blog, 'gid://wix/Blog/e2e-wix-site'), $post), 'a post in no category is in the site blog');
    },
    'intact' => static function () use ($mugGid, $orderGid): void {
        expect(product($mugGid)->get_status() === 'publish', 'the Wix mug is still published');
        expect(order(Kind::Order, $orderGid)->get_status() === 'completed', 'the Wix order is untouched');
    },
];
