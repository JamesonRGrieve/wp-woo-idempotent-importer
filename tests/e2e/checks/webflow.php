<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Webflow's end-to-end checks (the shape is described at checks() in assert.php). The fake site has a site
 * API token and names its Journal collection as the blog (wp-config-extra.php).
 */

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Webflow\Mapping\Fields;
use IdempotentImporter\Wp\ContentTypes;
use IdempotentImporter\Wp\Options;
use IdempotentImporter\Wp\Storefront;

$mugGid = 'gid://webflow/Product/64f1a2b3c4d5e6f708194d01';
$bowlGid = 'gid://webflow/Product/64f1a2b3c4d5e6f708194d02';
$orderGid = 'gid://webflow/Order/a3f9c1';
$teamGid = 'gid://webflow/Collection/64f1a2b3c4d5e6f708192b05';

return [
    // Webflow, with store settings mirrored (Shopify's checks switch them on too; this keeps a Webflow-only run whole).
    'configure' => static function (): void {
        selectSource('webflow');
        update_option(Settings::OPTION, [...Options::array(Settings::OPTION), 'store_settings' => true], false);
    },
    'v1' => static function () use ($mugGid, $bowlGid, $orderGid, $teamGid): void {
        expect(get_option('woocommerce_currency') === 'CAD' && get_option('timezone_string') === 'America/Vancouver', "the site's currency and timezone");
        $asset = target(Kind::File, 'gid://webflow/Asset/64f1a2b3c4d5e6f708196f01');
        expect(get_post_type($asset) === 'attachment' && get_post_meta($asset, '_wp_attachment_image_alt', true) === "The studio's kiln room", 'an asset is a media library image with its alt text');

        $mug = product($mugGid);
        expect($mug instanceof WC_Product_Simple && $mug->get_regular_price() === '34' && $mug->get_sale_price() === '28', 'a compare-at price above the price: on sale, in dollars');
        expect($mug->get_sku() === 'FH-MUG-1' && $mug->get_stock_quantity() === 12 && $mug->get_image_id() > 0, 'SKU, inventory and image');
        expect(wp_get_post_terms($mug->get_id(), 'product_cat', ['fields' => 'names']) === ['Mugs'], 'category → product category');
        $bowl = product($bowlGid);
        expect($bowl instanceof WC_Product_Variable && count($bowl->get_children()) === 2, 'the bowl is variable with a variation per SKU');
        $celadon = variation('gid://webflow/Variant/64f1a2b3c4d5e6f708195e03');
        expect(!$celadon->get_manage_stock() && $celadon->get_regular_price() === '44', 'infinite inventory is unmanaged');

        $order = order(Kind::Order, $orderGid);
        expect($order->get_status() === 'completed' && $order->get_order_number() === 'a3f9c1', 'fulfilled → completed, Webflow id kept');
        expect(money($order->get_total()) === '110.25' && money($order->get_total_tax()) === '5.25', "Webflow's total and its order-level tax");
        expect(money($order->get_shipping_total()) === '15.00' && money($order->get_discount_total()) === '10.00', 'shipping and discount');
        $lines = array_values($order->get_items());
        expect(count($lines) === 2 && $lines[1] instanceof WC_Order_Item_Product && $lines[1]->get_variation_id() === $celadon->get_id(), 'a line points at its SKU');
        $ada = user(Fields::customerId('ada.thorne@example.com'));
        expect($order->get_customer_id() === $ada->ID && $ada->user_email === 'ada.thorne@example.com', 'buyers gathered from orders become customers, linked to their orders');
        expect(order(Kind::Order, 'gid://webflow/Order/b71d04')->get_status() === 'processing', 'paid and unfulfilled → processing');
        expect(product($mugGid)->get_stock_quantity() === 12, 'mirroring orders did not reduce stock');

        $about = mirroredPost(Kind::Page, 'gid://webflow/Page/64f1a2b3c4d5e6f708197a01');
        expect($about->post_type === 'page' && str_contains($about->post_content, 'We throw every piece by hand') && str_contains($about->post_content, '<img'), "a page's DOM text and images become its content");
        expect(services()->links()->targetId(Kind::Page, 'gid://webflow/Page/64f1a2b3c4d5e6f708197a02') === null, 'a collection template page is not a page');

        $journal = term(Kind::Blog, 'gid://webflow/Collection/64f1a2b3c4d5e6f708192b04', 'category');
        expect($journal->name === 'Journal', 'the blog collection is a post category');
        $kiln = mirroredPost(Kind::Article, 'gid://webflow/Item/64f1a2b3c4d5e6f708198b01');
        expect($kiln->post_status === 'publish' && has_post_thumbnail($kiln) && in_category($journal->term_id, $kiln), 'a post, in the blog, with its main image');
        expect(get_post_status(target(Kind::Article, 'gid://webflow/Item/64f1a2b3c4d5e6f708198b02')) === 'draft', 'a draft post stays a draft');

        $isla = mirroredPost(Kind::ContentItem, 'gid://webflow/Item/64f1a2b3c4d5e6f708199c01');
        expect($isla->post_type === ContentTypes::key($teamGid) && $isla->post_title === 'Isla Fenn', "another collection's item is a post of the collection's own post type");
        $fields = get_post_meta($isla->ID, '_mirror_fields', true);
        expect(is_array($fields) && ($fields['role'] ?? null) === 'Potter', 'its other fields are meta');
        expect(get_post_type(target(Kind::ContentType, $teamGid)) === ContentTypes::DEFINITION_TYPE, 'the collection is kept as a post type definition');

        $storefront = new Storefront(services()->settings(), services()->redirects(), services()->links());
        expect($storefront->target(LegacyPaths::normalise('/product/speckled-mug')) === get_permalink($mug->get_id()), 'old product URL → product');
        expect($storefront->target(LegacyPaths::normalise('/shop/mugs')) === get_term_link(target(Kind::Collection, 'gid://webflow/Category/64f1a2b3c4d5e6f708193c01')), 'a 301 redirect to a category URL → the mirrored category');
        expect($storefront->target(LegacyPaths::normalise('/old-studio')) === 'https://studio.example.org/fernhill', 'redirect elsewhere');
        expect($storefront->target(LegacyPaths::normalise('/journal/firing-the-anagama')) === get_permalink($kiln->ID), 'old post URL → post');
    },
    'v2' => static function () use ($mugGid, $orderGid): void {
        expect(product($mugGid)->get_sale_price() === '30', 'price updated');
        $refunded = order(Kind::Order, $orderGid);
        expect($refunded->get_status() === 'refunded' && count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '110.25', 'a refunded order has one refund of what was paid');
        $new = order(Kind::Order, 'gid://webflow/Order/c0e2aa');
        expect($new->get_status() === 'processing' && $new->get_customer_id() === user(Fields::customerId('cy.moreau@example.com'))->ID, 'new order, linked to the new customer');
        expect(get_post_status(target(Kind::Article, 'gid://webflow/Item/64f1a2b3c4d5e6f708198b03')) === 'publish', 'new post');
        $storefront = new Storefront(services()->settings(), services()->redirects(), services()->links());
        expect($storefront->target(LegacyPaths::normalise('/old-studio')) === null, 'a removed redirect no longer redirects');
    },
    'intact' => static function () use ($mugGid, $orderGid): void {
        expect(product($mugGid)->get_status() === 'publish', 'the Webflow mug is still published');
        expect(order(Kind::Order, $orderGid)->get_status() === 'refunded', 'the Webflow order is untouched');
    },
];
