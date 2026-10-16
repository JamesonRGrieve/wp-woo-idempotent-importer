<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Shopify's end-to-end checks (the shape is described at checks() in assert.php).
 */

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Options;
use IdempotentImporter\Wp\Storefront;

return [
    'configure' => static function (): void {
        update_option(Settings::OPTION, [
            'store_domain' => 'fixture-boutique.myshopify.com',
            'store_settings' => true,
            'shipping_zones' => true,
            'interval_minutes' => 60,
        ], false);
    },
    'v1' => static function (): void {
        $scarf = product('gid://shopify/Product/301');
        expect($scarf instanceof WC_Product_Simple, 'the scarf is a simple product');
        expect($scarf->get_regular_price() === '80' && $scarf->get_sale_price() === '60', 'compare-at → regular 80, sale 60');
        expect($scarf->get_sku() === 'SCARF-1' && $scarf->get_stock_quantity() === 7, 'SKU and stock');
        expect($scarf->get_global_unique_id() === '0123456789012', 'barcode → GTIN');
        expect($scarf->get_weight() === '120', 'weight in the store unit (grams, from the mirrored shop settings)');
        expect($scarf->get_image_id() > 0, 'featured image in the media library');
        expect(!str_contains($scarf->get_description(), 'shopify-fake'), 'embedded description image localised');
        expect($scarf->get_meta('_mirror_mf_custom.care') === 'Dry clean only.', 'metafield mirrored');
        expect(wp_get_post_terms($scarf->get_id(), 'product_brand', ['fields' => 'names']) === ['Atelier North'], 'vendor → brand');
        $facts = apply_filters('wdc_product_extra_facts', [], $scarf);
        expect(is_array($facts) && $facts['category_id'] === 'gid://shopify/TaxonomyCategory/aa-2-26'
            && $facts['metafields'] === ['custom.care' => 'Dry clean only.'], 'dynamic-collections facts: Shopify category and metafields');
        $stored = get_term_meta(target(Kind::Collection, 'gid://shopify/Collection/202'), '_wdc_rules', true);
        $saleRules = new Values((array) json_decode(is_string($stored) ? $stored : '', true));
        $ruleRows = $saleRules->records('rules');
        expect(
            !$saleRules->bool('disjunctive') && count($ruleRows) === 2 && $ruleRows[1]->nullableString('metafield') === 'custom.care',
            'smart-collection rules handed to dynamic collections'
        );
        expect(get_term_meta(target(Kind::Collection, 'gid://shopify/Collection/201'), '_wdc_rules', true) === '', 'a hand-picked collection gets no rules');

        $bra = product('gid://shopify/Product/302');
        expect($bra instanceof WC_Product_Variable && count($bra->get_children()) === 2, 'the bra is variable with 2 variations');
        expect(count($bra->get_gallery_image_ids()) === 1, 'second image in the gallery');
        expect(services()->links()->targetId(Kind::Product, 'gid://shopify/Product/303') === null, 'the gift card is not mirrored');

        $order = order(Kind::Order, 'gid://shopify/Order/801');
        expect($order->get_status() === 'completed', 'order #1001 completed');
        expect(money($order->get_total()) === '137.76' && $order->get_order_number() === '#1001', 'total and Shopify order number kept');
        expect(count($order->get_items()) === 2 && count($order->get_refunds()) === 1, 'two lines and one refund');
        expect(money($order->get_total_tax()) === '6.56', 'tax total');
        expect($order->get_customer_id() === target(Kind::Customer, 'gid://shopify/Customer/401'), 'linked to its customer');
        expect(count(wc_get_order_notes(['order_id' => $order->get_id()])) >= 2, 'timeline comment and fulfillment notes');
        expect(product('gid://shopify/Product/301')->get_stock_quantity() === 7, 'mirroring orders did not reduce stock');

        expect(order(Kind::Order, 'gid://shopify/Order/802')->get_status() === 'cancelled', 'order #1002 cancelled');
        $refunded = order(Kind::Order, 'gid://shopify/Order/804');
        expect(
            $refunded->get_status() === 'refunded' && count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '118.59',
            "fully refunded order #1004: Shopify's one refund, not WooCommerce's automatic one as well",
        );
        expect(order(Kind::DraftOrder, 'gid://shopify/DraftOrder/901')->get_status() === 'pending', 'open draft → pending order');

        $avery = user('gid://shopify/Customer/401');
        expect($avery->user_email === 'avery.lin@example.com' && $avery->locale === 'en_CA', 'customer, email and locale');
        expect(get_user_meta($avery->ID, 'billing_postcode', true) === 'T5J 1A1', 'billing address');

        expect(wc_get_coupon_id_by_code('FALL15') > 0 && wc_get_coupon_id_by_code('AUTUMN15') > 0, 'one coupon per code');
        $fall = new WC_Coupon('FALL15');
        expect(money($fall->get_amount()) === '15.00' && $fall->get_discount_type() === 'percent' && money($fall->get_minimum_amount()) === '50.00', 'coupon value and minimum');
        expect((new WC_Coupon('SHIPFREE'))->get_free_shipping(), 'free-shipping coupon');

        expect(get_post_status(target(Kind::Page, 'gid://shopify/Page/1001')) === 'publish', 'published page');
        expect(get_post_status(target(Kind::Page, 'gid://shopify/Page/1002')) === 'draft', 'unpublished page is a draft');
        $article = target(Kind::Article, 'gid://shopify/Article/1201');
        expect(has_category(target(Kind::Blog, 'gid://shopify/Blog/1101'), $article), 'article in its blog category');
        expect(get_comments(['post_id' => $article, 'status' => 'approve', 'count' => true]) === 1, 'approved comment');
        expect(get_comments(['post_id' => $article, 'status' => 'spam', 'count' => true]) === 1, 'spam comment kept as spam');

        $menuItems = wp_get_nav_menu_items(target(Kind::Menu, 'gid://shopify/Menu/1401')) ?: [];
        expect(count($menuItems) === 4, 'menu with nested items');

        expect(Options::int('wp_page_for_privacy_policy') === target(Kind::Policy, 'gid://shopify/ShopPolicy/12'), 'privacy policy page wired');
        expect(get_option('woocommerce_currency') === 'CAD' && get_option('woocommerce_weight_unit') === 'g', 'store settings');

        $methods = 0;
        foreach (WC_Shipping_Zones::get_zones() as $zone) {
            $methods += is_array($zone) && is_array($zone['shipping_methods'] ?? null) ? count($zone['shipping_methods']) : 0;
        }
        expect(count(WC_Shipping_Zones::get_zones()) === 1 && $methods === 2, 'shipping zone with flat rate + free shipping (weight band reported)');

        $storefront = new Storefront(services()->settings(), services()->redirects(), services()->links());
        expect($storefront->target('/products/silk-scarf') === get_permalink($scarf->get_id()), 'old product URL → product');
        expect($storefront->target('/collections/bras') === get_term_link(target(Kind::Collection, 'gid://shopify/Collection/201')), 'old collection URL → category');
        expect($storefront->target('/old-scarf') === get_permalink($scarf->get_id()), 'Shopify URL redirect followed to the product');
        expect($storefront->target('/instagram') === 'https://instagram.com/fixtureboutique', 'external redirect');
        expect($storefront->target('/policies/refund-policy') === get_permalink(target(Kind::Policy, 'gid://shopify/ShopPolicy/11')), 'policy URL');
    },
    'v2' => static function (): void {
        $scarf = product('gid://shopify/Product/301');
        expect($scarf->get_sale_price() === '55' && $scarf->get_stock_quantity() === 6, 'price and stock updated');
        expect(services()->links()->targetId(Kind::Product, 'gid://shopify/Product/302') === null, 'deleted product unlinked');
        expect(trashedProducts() === 1, 'deleted product trashed, not deleted');
        order(Kind::Order, 'gid://shopify/Order/803');
        expect(services()->links()->targetId(Kind::DraftOrder, 'gid://shopify/DraftOrder/901') === null, 'completed draft retired');
    },
    'intact' => static function (): void {
        expect(product('gid://shopify/Product/301')->get_status() === 'publish', 'the Shopify scarf is still published');
        expect(order(Kind::Order, 'gid://shopify/Order/801')->get_status() === 'completed', 'the Shopify order is untouched');
    },
];
