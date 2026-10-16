<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Squarespace's end-to-end checks (the shape is described at checks() in assert.php).
 */

use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Storefront;

$boardGid = 'gid://squarespace/Product/6b2000000000000000000011';
$scarfGid = 'gid://squarespace/Product/6b2000000000000000000012';
$orderGid = 'gid://squarespace/Order/6d4000000000000000000031';

return [
    'v1' => static function () use ($boardGid, $scarfGid, $orderGid): void {
        expect(get_option('timezone_string') === 'America/Halifax' && get_option('woocommerce_weight_unit') === 'kg', 'store settings: timezone, metric → kg');

        $board = product($boardGid);
        expect($board instanceof WC_Product_Simple && $board->get_name() === 'Cedar cutting board', 'the board is a simple product (one variant, no attributes)');
        expect($board->get_regular_price() === '48' && $board->get_sku() === 'SQS-BOARD-1' && $board->get_stock_quantity() === 9, 'price, SKU and stock');
        expect($board->get_global_unique_id() === '0662233440017', 'GTIN');
        expect($board->get_weight() === '1.2', 'weight in kilograms');
        expect($board->get_image_id() > 0 && count($board->get_gallery_image_ids()) === 1, 'first image featured, the other in the gallery');
        expect(wp_get_post_terms($board->get_id(), 'product_cat', ['fields' => 'names']) === ['Kitchen'], 'store page → product category');
        expect(wp_get_post_terms($board->get_id(), 'product_tag', ['fields' => 'names']) === ['cedar', 'kitchen'], 'tags');

        $scarf = product($scarfGid);
        expect($scarf instanceof WC_Product_Variable && count($scarf->get_children()) === 2, 'the scarf is variable with 2 variations');
        $charcoalId = target(Kind::Variant, 'gid://squarespace/ProductVariant/a1b2c3d4-0000-4000-8000-000000000121');
        $charcoal = wc_get_product($charcoalId);
        expect($charcoal instanceof WC_Product_Variation && $charcoal->get_regular_price() === '65' && $charcoal->get_sale_price() === '55', 'a variant on sale');
        $moss = wc_get_product(target(Kind::Variant, 'gid://squarespace/ProductVariant/a1b2c3d4-0000-4000-8000-000000000122'));
        expect($moss instanceof WC_Product_Variation && $moss->get_stock_quantity() === 0 && $moss->get_image_id() > 0, "the moss variant's stock and image");
        expect(get_post_status(target(Kind::Product, 'gid://squarespace/Product/6b2000000000000000000013')) === 'private', 'a hidden product is kept, private');
        $ebook = product('gid://squarespace/Product/6b2000000000000000000014');
        expect($ebook->is_virtual() && $ebook->get_regular_price() === '12', 'a download is a virtual product');
        expect(services()->links()->targetId(Kind::Product, 'gid://squarespace/Product/6b2000000000000000000015') === null, 'a gift card is not mirrored');

        $order = order(Kind::Order, $orderGid);
        expect($order->get_status() === 'completed' && $order->get_order_number() === '#1001', 'fulfilled order → completed, Squarespace number kept');
        expect(money($order->get_total()) === '165.90' && money($order->get_total_tax()) === '7.90', "Squarespace's total and tax");
        expect(money($order->get_shipping_total()) === '12.00' && money($order->get_discount_total()) === '5.00', 'shipping and promo discount');
        $lines = array_values($order->get_items());
        expect(count($lines) === 2 && $lines[1] instanceof WC_Order_Item_Product && $lines[1]->get_variation_id() === $charcoalId, 'the scarf line points at its variation');
        expect($lines[0] instanceof WC_Order_Item_Product && $lines[0]->get_product_id() === $board->get_id() && $lines[0]->get_variation_id() === 0, 'the board line points at the simple product');
        expect(count($order->get_coupon_codes()) === 1, 'the promo code');
        expect($order->get_transaction_id() === 'ch_000501' && $order->get_payment_method_title() === 'STRIPE (VISA)', 'payment from the transactions');
        expect($order->get_customer_id() === user('gid://squarespace/Profile/6c3000000000000000000021')->ID, 'linked to its customer');
        expect(count(wc_get_order_notes(['order_id' => $order->get_id()])) >= 2, 'fulfillment and internal notes');
        expect(order(Kind::Order, 'gid://squarespace/Order/6d4000000000000000000032')->get_status() === 'pending', 'unpaid guest order');
        expect(product($boardGid)->get_stock_quantity() === 9, 'mirroring orders did not reduce stock');

        $marin = user('gid://squarespace/Profile/6c3000000000000000000021');
        expect($marin->user_login === 'squarespace-6c3000000000000000000021' && $marin->user_email === 'marin@example.com', 'customer and email');
        expect(get_user_meta($marin->ID, 'billing_postcode', true) === 'B0J 2C0', 'profile address → billing');
        user('gid://squarespace/Profile/6c3000000000000000000022');
        expect(services()->links()->targetId(Kind::Customer, 'gid://squarespace/Profile/6c3000000000000000000023') === null, 'a mailing-list subscriber is not a customer');

        $welcome = new WC_Coupon('SQS-WELCOME10');
        expect($welcome->get_discount_type() === 'percent' && money($welcome->get_amount()) === '10.00' && $welcome->get_usage_limit_per_user() === 1, 'percentage off, once per customer');
        $fiveOff = new WC_Coupon('SQS-FIVEOFF');
        expect($fiveOff->get_discount_type() === 'fixed_cart' && money($fiveOff->get_minimum_amount()) === '50.00' && $fiveOff->get_usage_limit() === 100, 'amount off above a cart total, limited uses');
        expect((new WC_Coupon('SQS-SHIPFREE'))->get_free_shipping(), 'free-shipping promo code');
        expect((new WC_Coupon('SQS-SCARF15'))->get_product_ids() === [$scarf->get_id()], 'a percentage off one product');

        $storefront = new Storefront(services()->settings(), services()->redirects(), services()->links());
        expect($storefront->target(LegacyPaths::normalise('/kitchen/p/cedar-cutting-board')) === get_permalink($board->get_id()), 'old product URL → product');
        expect($storefront->target(LegacyPaths::normalise('/kitchen/')) === get_term_link(target(Kind::Collection, 'gid://squarespace/StorePage/6a1000000000000000000001')), 'old store page URL → category');
    },
    'v2' => static function () use ($boardGid, $orderGid): void {
        expect(product($boardGid)->get_regular_price() === '52', 'price updated');
        expect(services()->links()->targetId(Kind::Product, 'gid://squarespace/Product/6b2000000000000000000013') === null, 'deleted product unlinked');
        expect(trashedProducts() === 5, 'deleted product trashed beside the earlier deletions');
        $refunded = order(Kind::Order, $orderGid);
        expect($refunded->get_status() === 'completed' && count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '10.00', 'partial refund mirrored');
        $new = order(Kind::Order, 'gid://squarespace/Order/6d4000000000000000000033');
        expect($new->get_status() === 'processing' && $new->get_customer_id() === user('gid://squarespace/Profile/6c3000000000000000000024')->ID, 'new paid order → processing, linked to the new customer');
    },
    'intact' => static function () use ($boardGid, $orderGid): void {
        expect(product($boardGid)->get_status() === 'publish', 'the Squarespace board is still published');
        expect(order(Kind::Order, $orderGid)->get_status() === 'completed', 'the Squarespace order is untouched');
    },
];
