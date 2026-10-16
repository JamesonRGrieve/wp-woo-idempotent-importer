<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Magento's end-to-end checks (the shape is described at checks() in assert.php).
 */

use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Storefront;

return [
    'v1' => static function (): void {
        expect(get_option('woocommerce_weight_unit') === 'kg' && get_option('woocommerce_currency') === 'CAD', 'store settings: weight unit kgs → kg, currency');
        expect(get_option('timezone_string') === 'America/Vancouver', 'store timezone');

        $kettle = product('gid://magento/Product/11');
        expect($kettle instanceof WC_Product_Simple && $kettle->get_name() === 'Copper kettle', 'the kettle is a simple product');
        expect($kettle->get_regular_price() === '89.99' && $kettle->get_sale_price() === '79.99', 'price and special price → sale price');
        expect($kettle->get_sku() === 'MAG-KETTLE-1' && $kettle->get_stock_quantity() === 7, 'SKU and stock');
        expect($kettle->get_weight() === '1.4', 'weight in kilograms');
        expect($kettle->get_image_id() > 0 && count($kettle->get_gallery_image_ids()) === 1, 'base image featured, the other enabled image in the gallery');
        expect(wp_get_post_terms($kettle->get_id(), 'product_cat', ['fields' => 'names']) === ['Galley'], 'category (not the store root) → product category');
        expect(get_term_meta(target(Kind::Collection, 'gid://magento/Category/3'), 'thumbnail_id', true) !== '', 'category image');

        $jacket = product('gid://magento/Product/12');
        expect($jacket instanceof WC_Product_Variable && count($jacket->get_children()) === 2, 'the configurable jacket is variable with its 2 child products');
        $navyId = target(Kind::Variant, 'gid://magento/Variant/122');
        $navy = wc_get_product($navyId);
        expect($navy instanceof WC_Product_Variation && $navy->get_regular_price() === '159.5' && $navy->get_stock_quantity() === 0 && $navy->get_image_id() > 0, "the navy child's price, stock and image");
        $red = wc_get_product(target(Kind::Variant, 'gid://magento/Variant/121'));
        expect($red instanceof WC_Product_Variation && $red->get_regular_price() === '149' && $red->get_stock_quantity() === 4, "the red child's price and stock");
        expect(get_post_status(target(Kind::Product, 'gid://magento/Product/13')) === 'private', 'a disabled product is kept, private');
        $print = product('gid://magento/Product/14');
        expect($print->is_virtual() && $print->get_regular_price() === '15' && !$print->get_manage_stock(), 'a downloadable product is virtual, stock unmanaged');
        expect(services()->links()->targetId(Kind::Product, 'gid://magento/Product/15') === null, 'a bundle is not mirrored');

        $order = order(Kind::Order, 'gid://magento/Order/501');
        expect($order->get_status() === 'completed' && $order->get_order_number() === '#000000501', 'complete → completed, Magento increment id kept');
        expect(money($order->get_total()) === '258.82' && money($order->get_total_tax()) === '12.33', "Magento's total and tax");
        expect(money($order->get_shipping_total()) === '12.00' && money($order->get_discount_total()) === '5.00', 'shipping and coupon discount');
        $lines = array_values($order->get_items());
        expect(count($lines) === 2, "the configurable product's parent and child lines are one line");
        expect($lines[1] instanceof WC_Order_Item_Product && $lines[1]->get_variation_id() === $navyId, 'the jacket line points at the navy variation');
        expect($lines[0] instanceof WC_Order_Item_Product && $lines[0]->get_product_id() === $kettle->get_id() && $lines[0]->get_variation_id() === 0, 'the kettle line points at the simple product');
        expect(count($order->get_coupon_codes()) === 1, 'the coupon code');
        expect($order->get_payment_method_title() === 'Check / Money order', 'payment method title');
        expect($order->get_customer_id() === user('gid://magento/Customer/41')->ID, 'linked to its customer');
        expect(count(wc_get_order_notes(['order_id' => $order->get_id()])) >= 2, 'shipment and comment notes');
        expect(order(Kind::Order, 'gid://magento/Order/502')->get_status() === 'pending', 'new guest order → pending');
        expect(product('gid://magento/Product/11')->get_stock_quantity() === 7, 'mirroring orders did not reduce stock');

        $maeve = user('gid://magento/Customer/41');
        expect($maeve->user_login === 'magento-41' && $maeve->user_email === 'maeve@example.com', 'customer and lower-cased email');
        expect(get_user_meta($maeve->ID, 'billing_postcode', true) === 'V8W 1T3' && get_user_meta($maeve->ID, 'billing_state', true) === 'BC', 'default billing address, region code');

        $welcome = new WC_Coupon('MAG-WELCOME5');
        expect($welcome->get_discount_type() === 'fixed_cart' && money($welcome->get_amount()) === '5.00' && money($welcome->get_minimum_amount()) === '50.00', 'cart fixed amount with a minimum subtotal');
        expect($welcome->get_usage_limit_per_user() === 1, 'once per customer');
        $fall = new WC_Coupon('MAG-FALL15');
        expect($fall->get_discount_type() === 'percent' && money($fall->get_amount()) === '15.00' && $fall->get_usage_limit() === 100, 'percentage per item, uses per coupon');
        expect((new WC_Coupon('MAG-SHIPFREE'))->get_free_shipping(), 'free shipping for the whole cart');
        expect(wc_get_coupon_id_by_code('MAG-JACKET20') === 0 && wc_get_coupon_id_by_code('MAG-SUMMER') === 0, 'an item-conditioned rule and an inactive rule are not mirrored');

        expect(get_post_status(target(Kind::Page, 'gid://magento/CmsPage/2')) === 'publish', 'CMS page');
        expect(get_post_status(target(Kind::Page, 'gid://magento/CmsPage/3')) === 'draft', 'inactive CMS page → draft');

        $storefront = new Storefront(services()->settings(), services()->redirects(), services()->links());
        expect($storefront->target(LegacyPaths::normalise('/copper-kettle.html')) === get_permalink($kettle->get_id()), 'old product URL → product');
        expect($storefront->target(LegacyPaths::normalise('/outerwear/rain-jackets.html')) === get_term_link(target(Kind::Collection, 'gid://magento/Category/5')), 'old category URL → category');
        expect($storefront->target(LegacyPaths::normalise('/company/our-story')) === get_permalink(target(Kind::Page, 'gid://magento/CmsPage/2')), 'old CMS page URL → page');
    },
    'v2' => static function (): void {
        expect(product('gid://magento/Product/11')->get_regular_price() === '94.99', 'price updated');
        expect(services()->links()->targetId(Kind::Product, 'gid://magento/Product/13') === null, 'deleted product unlinked');
        expect(trashedProducts() === 7, 'deleted product trashed beside the earlier deletions');
        $refunded = order(Kind::Order, 'gid://magento/Order/501');
        expect($refunded->get_status() === 'completed' && count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '10.00', 'credit memo → partial refund');
        $new = order(Kind::Order, 'gid://magento/Order/503');
        expect($new->get_status() === 'processing' && $new->get_customer_id() === user('gid://magento/Customer/43')->ID, 'new processing order, linked to the new customer');
        expect(get_post_status(target(Kind::Page, 'gid://magento/CmsPage/4')) === 'publish', 'new CMS page');
    },
    'intact' => static function (): void {
        expect(product('gid://magento/Product/11')->get_status() === 'publish', 'the Magento kettle is still published');
        expect(order(Kind::Order, 'gid://magento/Order/501')->get_status() === 'completed', 'the Magento order is untouched');
    },
];
