<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Ecwid's end-to-end checks (the shape is described at checks() in assert.php).
 */

use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Options;
use IdempotentImporter\Wp\Storefront;

$candleGid = 'gid://ecwid/Product/9201';
$orderGid = 'gid://ecwid/Order/CW-1001';

return [
    'v1' => static function () use ($candleGid, $orderGid): void {
        expect(get_option('woocommerce_currency') === 'CAD' && get_option('woocommerce_weight_unit') === 'kg', 'store settings: currency, KILOGRAM → kg');
        expect(get_option('timezone_string') === 'America/Edmonton' && get_option('woocommerce_prices_include_tax') === 'no', 'store timezone, net prices');
        expect(Options::int('wp_page_for_privacy_policy') === target(Kind::Policy, 'gid://ecwid/Policy/7400100-PRIVACY_STATEMENT'), 'privacy statement page wired');
        expect(Options::int('woocommerce_terms_page_id') === target(Kind::Policy, 'gid://ecwid/Policy/7400100-TERMS'), 'terms page wired');

        $candle = product($candleGid);
        expect($candle instanceof WC_Product_Simple && $candle->get_name() === 'Cedar candle', 'the candle is a simple product (no variations)');
        expect($candle->get_regular_price() === '28' && $candle->get_sku() === 'ECW-CANDLE-1' && $candle->get_stock_quantity() === 12, 'price, SKU and stock');
        expect($candle->get_global_unique_id() === '0774455660013', 'UPC attribute → GTIN');
        expect($candle->get_weight() === '0.42', "weight in the store's unit");
        expect($candle->get_image_id() > 0 && count($candle->get_gallery_image_ids()) === 1, 'main image featured, the other in the gallery');
        expect(wp_get_post_terms($candle->get_id(), 'product_cat', ['fields' => 'names']) === ['Candles'], 'category → product category');
        expect(wp_get_post_terms($candle->get_id(), 'product_brand', ['fields' => 'names']) === ['Cedarwick'], 'brand attribute → WooCommerce brand');

        $melts = product('gid://ecwid/Product/9202');
        expect($melts instanceof WC_Product_Variable && count($melts->get_children()) === 2, 'the sampler is variable with 2 variations');
        $firId = target(Kind::Variant, 'gid://ecwid/Variant/920201');
        $fir = wc_get_product($firId);
        expect($fir instanceof WC_Product_Variation && $fir->get_regular_price() === '16' && $fir->get_sale_price() === '14', 'a compare-to price above the price: on sale');
        expect($fir instanceof WC_Product_Variation && $fir->get_backorders() === 'yes' && $fir->get_stock_quantity() === 20 && $fir->get_image_id() > 0, 'pre-orders allow backorders; stock and image');
        $birch = wc_get_product(target(Kind::Variant, 'gid://ecwid/Variant/920202'));
        expect($birch instanceof WC_Product_Variation && !$birch->get_manage_stock(), 'unlimited stock is unmanaged');
        expect(get_post_status(target(Kind::Product, 'gid://ecwid/Product/9203')) === 'private', 'a disabled product is kept, private');

        $order = order(Kind::Order, $orderGid);
        expect($order->get_status() === 'completed' && $order->get_order_number() === 'CW-1001', 'paid and shipped → completed, Ecwid id kept');
        expect(money($order->get_total()) === '80.85' && money($order->get_total_tax()) === '3.85', "Ecwid's total and tax");
        expect(money($order->get_shipping_total()) === '12.00' && money($order->get_discount_total()) === '5.00', 'shipping and coupon discount');
        expect($order->get_transaction_id() === 'ch_ecw_3Pz81', 'payment transaction id');
        $lines = array_values($order->get_items());
        expect(count($lines) === 2 && $lines[1] instanceof WC_Order_Item_Product && $lines[1]->get_variation_id() === $firId, 'the sampler line points at its variation');
        expect($lines[0] instanceof WC_Order_Item_Product && $lines[0]->get_product_id() === $candle->get_id() && $lines[0]->get_variation_id() === 0, 'the candle line points at the simple product');
        expect(count($order->get_coupon_codes()) === 1, 'the coupon code');
        expect($order->get_customer_id() === user('gid://ecwid/Customer/9401')->ID, 'linked to its customer');
        expect(count(wc_get_order_notes(['order_id' => $order->get_id()])) >= 3, 'tracking, shipping label and private notes');
        expect(order(Kind::Order, 'gid://ecwid/Order/CW-1002')->get_status() === 'pending', 'an order awaiting payment');
        expect(product($candleGid)->get_stock_quantity() === 12, 'mirroring orders did not reduce stock');

        $rowan = user('gid://ecwid/Customer/9401');
        expect($rowan->user_login === 'ecwid-9401' && $rowan->user_email === 'rowan.pike@example.com', 'customer and email');
        expect(get_user_meta($rowan->ID, 'billing_postcode', true) === 'T1L 1A1', 'billing address');

        $welcome = new WC_Coupon('ECW-WELCOME5');
        expect($welcome->get_discount_type() === 'fixed_cart' && money($welcome->get_amount()) === '5.00' && money($welcome->get_minimum_amount()) === '30.00', 'amount off the order, minimum subtotal');
        expect((new WC_Coupon('ECW-MELTS15'))->get_product_ids() === [$melts->get_id()], 'a percentage off one product');
        expect((new WC_Coupon('ECW-SHIPFREE'))->get_free_shipping(), 'free-shipping coupon');
        expect(wc_get_coupon_id_by_code('ECW-PAUSED') === 0 && wc_get_coupon_id_by_code('ECW-NEWONLY') === 0, 'a paused coupon and a new-customers-only coupon are not mirrored');

        expect(count(reviews($candle->get_id())) === 1, 'published review on the candle');
        expect(count(reviews($melts->get_id())) === 0, 'a review awaiting moderation is not approved');

        $storefront = new Storefront(services()->settings(), services()->redirects(), services()->links());
        expect($storefront->target(LegacyPaths::normalise('/products/cedar-candle')) === get_permalink($candle->get_id()), 'old product URL → product');
        expect($storefront->target(LegacyPaths::normalise('/cedar-gift')) === get_permalink($candle->get_id()), 'Instant Site redirect to a product URL → the mirrored product');
        expect($storefront->target(LegacyPaths::normalise('/holiday-sale')) === 'https://gifts.example.com/cedarwick', 'redirect elsewhere');
        expect($storefront->target(LegacyPaths::normalise('/products/wax-melts/')) === get_term_link(target(Kind::Collection, 'gid://ecwid/Category/9102')), 'old category URL → category');
    },
    'v2' => static function () use ($candleGid, $orderGid): void {
        expect(product($candleGid)->get_regular_price() === '29.5', 'price updated');
        expect(services()->links()->targetId(Kind::Product, 'gid://ecwid/Product/9203') === null, 'deleted product unlinked');
        expect(trashedProducts() === 9, 'deleted product trashed beside the earlier deletions');
        $refunded = order(Kind::Order, $orderGid);
        expect($refunded->get_status() === 'completed' && count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '29.40', 'refund mirrored');
        $new = order(Kind::Order, 'gid://ecwid/Order/CW-1003');
        expect($new->get_status() === 'processing' && $new->get_customer_id() === user('gid://ecwid/Customer/9403')->ID, 'new paid order → processing, linked to the new customer');
        $lines = array_values($new->get_items());
        expect($lines[0] instanceof WC_Order_Item_Product && $lines[0]->get_variation_id() === target(Kind::Variant, 'gid://ecwid/Variant/920202'), 'the new line points at its variation');
        expect(count(reviews(product('gid://ecwid/Product/9202')->get_id())) === 1, 'new published review on the sampler');
    },
    'intact' => static function () use ($candleGid, $orderGid): void {
        expect(product($candleGid)->get_status() === 'publish', 'the Ecwid candle is still published');
        expect(order(Kind::Order, $orderGid)->get_status() === 'completed', 'the Ecwid order is untouched');
    },
];
