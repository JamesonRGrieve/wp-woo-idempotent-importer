<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Lightspeed eCom's end-to-end checks (the shape is described at checks() in assert.php).
 */

use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Storefront;

$mugGid = 'gid://lightspeed/Product/501';
$orderGid = 'gid://lightspeed/Order/701';

return [
    'v1' => static function () use ($mugGid, $orderGid): void {
        expect(get_option('blogname') === 'Harbour Lane Goods' && get_option('woocommerce_currency') === 'CAD', "store settings: the website's name, currency");
        expect(get_option('woocommerce_prices_include_tax') === 'yes', 'a retail shop shows prices with tax');

        $mug = product($mugGid);
        expect($mug instanceof WC_Product_Simple && $mug->get_name() === 'Stoneware mug', 'the mug is a simple product (one variant, no options)');
        expect($mug->get_regular_price() === '26.25' && $mug->get_sku() === 'LS-MUG-1' && $mug->get_stock_quantity() === 14, 'price with tax, SKU and stock');
        expect($mug->get_global_unique_id() === '0881122330011', 'EAN → GTIN');
        expect($mug->get_weight() === '0.35', 'weight in kilograms');
        expect($mug->get_image_id() > 0 && count($mug->get_gallery_image_ids()) === 1, 'first image featured, the other in the gallery');
        expect(wp_get_post_terms($mug->get_id(), 'product_cat', ['fields' => 'names']) === ['Tableware'], 'category → product category');
        expect(wp_get_post_terms($mug->get_id(), 'product_brand', ['fields' => 'names']) === ['Harbour Lane'], 'brand → WooCommerce brand');

        $towel = product('gid://lightspeed/Product/502');
        expect($towel instanceof WC_Product_Variable && count($towel->get_children()) === 2, 'the towel is variable with 2 variations');
        $slateId = target(Kind::Variant, 'gid://lightspeed/Variant/5022');
        $sand = wc_get_product(target(Kind::Variant, 'gid://lightspeed/Variant/5021'));
        expect($sand instanceof WC_Product_Variation && $sand->get_regular_price() === '26.25' && $sand->get_sale_price() === '21', 'an old price above the price: on sale');
        expect($sand instanceof WC_Product_Variation && $sand->get_backorders() === 'yes' && $sand->get_stock_quantity() === 6, 'indicator stock tracking allows backorders');
        $slate = wc_get_product($slateId);
        expect($slate instanceof WC_Product_Variation && $slate->get_weight() === '0.12' && $slate->get_image_id() > 0, "the slate variant's weight in grams and its image");
        expect(get_post_status(target(Kind::Product, 'gid://lightspeed/Product/503')) === 'private', 'a hidden product is kept, private');

        $order = order(Kind::Order, $orderGid);
        expect($order->get_status() === 'completed' && $order->get_order_number() === 'ORD00001', 'shipped order → completed, Lightspeed number kept');
        expect(money($order->get_total()) === '78.75' && money($order->get_total_tax()) === '3.75', "Lightspeed's total and tax");
        expect(money($order->get_shipping_total()) === '10.00' && money($order->get_discount_total()) === '5.00', 'shipping and coupon discount');
        $lines = array_values($order->get_items());
        expect(count($lines) === 2 && $lines[1] instanceof WC_Order_Item_Product && $lines[1]->get_variation_id() === $slateId, 'the towel line points at its variation');
        expect($lines[0] instanceof WC_Order_Item_Product && $lines[0]->get_product_id() === $mug->get_id() && $lines[0]->get_variation_id() === 0, 'the mug line points at the simple product');
        expect(count($order->get_coupon_codes()) === 1, 'the coupon code');
        expect($order->get_customer_id() === user('gid://lightspeed/Customer/801')->ID, 'linked to its customer');
        expect(count(wc_get_order_notes(['order_id' => $order->get_id()])) >= 2, 'shipment and memo notes');
        expect(order(Kind::Order, 'gid://lightspeed/Order/702')->get_status() === 'pending', 'an order awaiting payment');
        expect(product($mugGid)->get_stock_quantity() === 14, 'mirroring orders did not reduce stock');

        $marguerite = user('gid://lightspeed/Customer/801');
        expect($marguerite->user_login === 'lightspeed-801' && $marguerite->user_email === 'marguerite@example.com', 'customer and email');
        expect(get_user_meta($marguerite->ID, 'billing_postcode', true) === 'B3H 1X3', 'billing address');

        $welcome = new WC_Coupon('LS-WELCOME5');
        expect($welcome->get_discount_type() === 'fixed_cart' && money($welcome->get_amount()) === '5.00' && money($welcome->get_minimum_amount()) === '30.00', 'amount off the order, minimum spend');
        expect((new WC_Coupon('LS-TOWELS15'))->get_product_ids() === [$towel->get_id()], 'a percentage off one product');
        expect((new WC_Coupon('LS-SHIPFREE'))->get_free_shipping(), 'free-shipping code');
        expect(wc_get_coupon_id_by_code('LS-RETIRED') === 0 && wc_get_coupon_id_by_code('LS-SHIPHALF') === 0, 'an inactive code and a shipping discount are not mirrored');

        expect(get_post_status(target(Kind::Page, 'gid://lightspeed/Textpage/1101')) === 'publish', 'text page');
        expect(get_post_status(target(Kind::Page, 'gid://lightspeed/Textpage/1102')) === 'draft', 'hidden text page → draft');
        $article = target(Kind::Article, 'gid://lightspeed/Article/1301');
        expect(has_category(target(Kind::Blog, 'gid://lightspeed/Blog/1201'), $article), 'article in its blog category');
        expect(get_post_thumbnail_id($article) > 0, 'article image');

        expect(count(reviews($mug->get_id())) === 1, 'visible review on the mug');
        expect(count(reviews($towel->get_id())) === 0, 'a hidden review is not approved');

        $storefront = new Storefront(services()->settings(), services()->redirects(), services()->links());
        expect($storefront->target(LegacyPaths::normalise('/stoneware-mug.html')) === get_permalink($mug->get_id()), 'old product URL → product');
        expect($storefront->target(LegacyPaths::normalise('/harbour-mug')) === get_permalink($mug->get_id()), 'Lightspeed redirect to a product URL → the mirrored product');
        expect($storefront->target(LegacyPaths::normalise('/harbour-clearance')) === 'https://sale.example.com/harbour', 'redirect elsewhere');
        expect($storefront->target(LegacyPaths::normalise('/tableware/tea-towels/')) === get_term_link(target(Kind::Collection, 'gid://lightspeed/Category/402')), 'old category URL → category');
        expect($storefront->target(LegacyPaths::normalise('/service/our-workshop/')) === get_permalink(target(Kind::Page, 'gid://lightspeed/Textpage/1101')), 'old text page URL → page');
        expect($storefront->target(LegacyPaths::normalise('/blogs/journal/caring-for-linen.html')) === get_permalink($article), 'old article URL → post');
    },
    'v2' => static function () use ($mugGid, $orderGid): void {
        expect(product($mugGid)->get_regular_price() === '27.3', 'price updated');
        expect(services()->links()->targetId(Kind::Product, 'gid://lightspeed/Product/503') === null, 'deleted product unlinked');
        expect(trashedProducts() === 8, 'deleted product trashed beside the earlier deletions');
        $refunded = order(Kind::Order, $orderGid);
        expect($refunded->get_status() === 'completed' && count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '26.25', 'credit note mirrored as a refund');
        $new = order(Kind::Order, 'gid://lightspeed/Order/703');
        expect($new->get_status() === 'processing' && $new->get_customer_id() === user('gid://lightspeed/Customer/803')->ID, 'new order awaiting shipment → processing, linked to the new customer');
        target(Kind::Article, 'gid://lightspeed/Article/1302');
        expect(count(reviews(product('gid://lightspeed/Product/502')->get_id())) === 1, 'new visible review on the towel');
    },
    'intact' => static function () use ($mugGid, $orderGid): void {
        expect(product($mugGid)->get_status() === 'publish', 'the Lightspeed mug is still published');
        expect(order(Kind::Order, $orderGid)->get_status() === 'completed', 'the Lightspeed order is untouched');
    },
];
