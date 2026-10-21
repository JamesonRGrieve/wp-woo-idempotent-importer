<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * BigCommerce's end-to-end checks (the shape is described at checks() in assert.php).
 */

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\BigCommerce\BigCommerceAdapter;
use IdempotentImporter\Wp\GiftCardAccount;
use IdempotentImporter\Wp\Options;
use IdempotentImporter\Wp\Storefront;

return [
    // BigCommerce, with its gift certificates mirrored into Square Gift Cards (the fake Square in wp-config).
    'configure' => static function (): void {
        selectSource(BigCommerceAdapter::ID);
        update_option(Settings::OPTION, [...Options::array(Settings::OPTION), 'kinds' => [...Settings::DEFAULTS['kinds'], Kind::GiftCard->value]], false);
    },
    'v1' => static function (): void {
        $card = services()->giftCards()->find('gid://bigcommerce/GiftCertificate/901') ?? throw new RuntimeException('FAILED: the gift certificate has no card');
        $ingrid = user('gid://bigcommerce/Customer/41');
        expect($card->gan === '4KZX0OUHIJHC' && $card->squareId === 'GC_901', 'the gift certificate is a Square gift card with its own code as its number');
        expect($card->sourceBalance === '50' && $card->loadedBalance === '50' && $card->userId === $ingrid->ID, 'its balance is loaded, and it is the buyer\'s');
        $pending = services()->giftCards()->find('gid://bigcommerce/GiftCertificate/902') ?? throw new RuntimeException('FAILED: the pending certificate has no card');
        expect($pending->gan === '9415999166351453' && $pending->loadedBalance === '0' && $pending->state === 'disabled', 'a code Square cannot take gets a new number; an unpaid certificate carries no balance');
        wp_set_current_user($ingrid->ID);
        ob_start();
        (new GiftCardAccount(services()->giftCards()))->render();
        $page = (string) ob_get_clean();
        wp_set_current_user(0);
        expect(str_contains($page, '4KZX 0OUH IJHC') && str_contains($page, 'Brought into Square'), "the customer's account shows the card's number and ledger");
        expect(get_option('woocommerce_weight_unit') === 'kg' && get_option('woocommerce_currency') === 'CAD', 'store settings: weight unit KGS → kg, currency');

        $mug = product('gid://bigcommerce/Product/111');
        expect($mug instanceof WC_Product_Simple && $mug->get_name() === 'Enamel trail mug', 'the mug is a simple product (its base variant)');
        expect($mug->get_regular_price() === '24' && $mug->get_sku() === 'BC-MUG-1' && $mug->get_stock_quantity() === 12, 'price, SKU and product-level stock');
        expect($mug->get_global_unique_id() === '0778899100018', 'GTIN');
        expect($mug->get_weight() === '0.4', 'weight in the store unit');
        expect($mug->get_image_id() > 0 && count($mug->get_gallery_image_ids()) === 1, 'thumbnail featured, the other image in the gallery');
        expect(wp_get_post_terms($mug->get_id(), 'product_cat', ['fields' => 'names']) === ['Camp Kitchen'], 'category → product category');
        expect(wp_get_post_terms($mug->get_id(), 'product_brand', ['fields' => 'names']) === ['Northern Trail'], 'brand → WooCommerce brand');
        expect($mug->get_meta('_mirror_custom_fields') === [['name' => 'Material', 'value' => 'Enamel steel']], 'custom fields mirrored as meta');
        expect(get_term_meta(target(Kind::Collection, 'gid://bigcommerce/Category/21'), 'thumbnail_id', true) !== '', 'category image');

        $toque = product('gid://bigcommerce/Product/112');
        expect($toque instanceof WC_Product_Variable && count($toque->get_children()) === 2, 'the toque is variable with 2 variations');
        $navyId = target(Kind::Variant, 'gid://bigcommerce/Variant/1122');
        $navy = wc_get_product($navyId);
        expect($navy instanceof WC_Product_Variation && $navy->get_regular_price() === '32.5' && $navy->get_stock_quantity() === 0, "the navy variant's own price and stock");
        $red = wc_get_product(target(Kind::Variant, 'gid://bigcommerce/Variant/1121'));
        expect($red instanceof WC_Product_Variation && $red->get_regular_price() === '30' && $red->get_stock_quantity() === 5, "the red variant inherits the product's price");
        expect(get_post_status(target(Kind::Product, 'gid://bigcommerce/Product/113')) === 'private', 'a hidden product is kept, private');

        $order = order(Kind::Order, 'gid://bigcommerce/Order/501');
        expect($order->get_status() === 'completed' && $order->get_order_number() === '#501', 'order 501 shipped → completed, BigCommerce number kept');
        expect(money($order->get_total()) === '92.40' && money($order->get_total_tax()) === '4.40', "BigCommerce's total and tax");
        expect(money($order->get_shipping_total()) === '12.00' && money($order->get_discount_total()) === '4.50', 'shipping and coupon discount');
        $lines = array_values($order->get_items());
        expect(count($lines) === 2 && $lines[1] instanceof WC_Order_Item_Product && $lines[1]->get_variation_id() === $navyId, 'the toque line points at its variation');
        expect($lines[0] instanceof WC_Order_Item_Product && $lines[0]->get_product_id() === $mug->get_id() && $lines[0]->get_variation_id() === 0, 'the mug line points at the simple product');
        expect(count($order->get_coupon_codes()) === 1, 'the coupon code');
        expect($order->get_customer_id() === user('gid://bigcommerce/Customer/41')->ID, 'linked to its customer');
        expect(count(wc_get_order_notes(['order_id' => $order->get_id()])) >= 2, 'shipment and staff notes');
        expect(order(Kind::Order, 'gid://bigcommerce/Order/502')->get_status() === 'pending', 'pending guest order');
        expect(product('gid://bigcommerce/Product/111')->get_stock_quantity() === 12, 'mirroring orders did not reduce stock');

        $ingrid = user('gid://bigcommerce/Customer/41');
        expect($ingrid->user_login === 'bigcommerce-41' && $ingrid->user_email === 'ingrid@example.com', 'customer and email');
        expect(get_user_meta($ingrid->ID, 'billing_postcode', true) === 'T1W 2B2', 'first saved address → billing');

        $welcome = new WC_Coupon('BC-WELCOME');
        expect($welcome->get_discount_type() === 'fixed_cart' && money($welcome->get_amount()) === '4.50' && money($welcome->get_minimum_amount()) === '50.00', 'legacy coupon: amount off the order, minimum spend');
        expect((new WC_Coupon('BC-SHIPFREE'))->get_free_shipping(), 'free-shipping coupon');
        $fall = new WC_Coupon('BC-FALL15');
        expect($fall->get_discount_type() === 'percent' && money($fall->get_amount()) === '15.00', 'coupon promotion: percentage off the order');
        expect(wc_get_coupon_id_by_code('BC-TOQUE20') === 0 && wc_get_coupon_id_by_code('BC-SHIPHALF') === 0, 'a disabled coupon and a shipping discount are not mirrored');

        expect(get_post_status(target(Kind::Page, 'gid://bigcommerce/Page/81')) === 'publish', 'content page');
        expect(get_post_status(target(Kind::Page, 'gid://bigcommerce/Page/82')) === 'draft', 'hidden page → draft');
        expect(services()->links()->targetId(Kind::Page, 'gid://bigcommerce/Page/84') === null, 'a contact form is not a page');
        $post = target(Kind::Article, 'gid://bigcommerce/Post/91');
        expect(has_category(target(Kind::Blog, 'gid://bigcommerce/Blog/bcfix123'), $post), 'blog post in the blog category');
        expect(get_post_thumbnail_id($post) > 0, 'post thumbnail from the store');

        expect(count(reviews($mug->get_id())) === 1, 'approved review on the mug');
        expect(count(reviews($toque->get_id())) === 0, 'a pending review is not approved');

        $storefront = new Storefront(services()->settings(), services()->redirects(), services()->links());
        expect($storefront->target(LegacyPaths::normalise('/enamel-trail-mug/')) === get_permalink($mug->get_id()), 'old product URL → product');
        expect($storefront->target(LegacyPaths::normalise('/old-trail-mug/')) === get_permalink($mug->get_id()), 'BigCommerce redirect to a product → the mirrored product');
        expect($storefront->target(LegacyPaths::normalise('/kitchen/')) === get_term_link(target(Kind::Collection, 'gid://bigcommerce/Category/21')), 'BigCommerce redirect to a category → the category');
        expect($storefront->target(LegacyPaths::normalise('/winter-sale/')) === 'https://northerntrail.example/sale', 'redirect to a URL');
        expect($storefront->target(LegacyPaths::normalise('/blog/packing-for-a-winter-hike/')) === get_permalink($post), 'old post URL → post');
        expect($storefront->target(LegacyPaths::normalise('/about-us/')) === get_permalink(target(Kind::Page, 'gid://bigcommerce/Page/81')), 'old page URL → page');
    },
    'v2' => static function (): void {
        $card = services()->giftCards()->find('gid://bigcommerce/GiftCertificate/901') ?? throw new RuntimeException('FAILED: the gift certificate has no card');
        expect($card->sourceBalance === '30' && $card->loadedBalance === '30', 'a balance spent at BigCommerce is deducted from the Square card');
        $kinds = array_map(static fn($entry): string => $entry->kind, services()->giftCards()->ledger($card->id));
        expect($kinds === ['issue', 'observed', 'activate', 'adjust_decrement'], 'the ledger: issued, spent at the source, brought into Square, deducted in Square');
        expect(product('gid://bigcommerce/Product/111')->get_regular_price() === '26', 'price updated');
        expect(services()->links()->targetId(Kind::Product, 'gid://bigcommerce/Product/113') === null, 'deleted product unlinked');
        expect(trashedProducts() === 4, 'deleted product trashed beside the earlier deletions');
        $refunded = order(Kind::Order, 'gid://bigcommerce/Order/501');
        expect($refunded->get_status() === 'completed' && count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '10.00', 'partial refund mirrored');
        $new = order(Kind::Order, 'gid://bigcommerce/Order/503');
        expect($new->get_status() === 'processing' && $new->get_customer_id() === user('gid://bigcommerce/Customer/43')->ID, 'new order awaiting fulfillment → processing, linked to the new customer');
        target(Kind::Article, 'gid://bigcommerce/Post/92');
        expect(count(reviews(product('gid://bigcommerce/Product/112')->get_id())) === 1, 'new approved review on the toque');
    },
    'intact' => static function (): void {
        expect(services()->giftCards()->find('gid://bigcommerce/GiftCertificate/901')?->loadedBalance === '30', 'the BigCommerce gift card is untouched');
        expect(product('gid://bigcommerce/Product/111')->get_status() === 'publish', 'the BigCommerce mug is still published');
        expect(order(Kind::Order, 'gid://bigcommerce/Order/501')->get_status() === 'completed', 'the BigCommerce order is untouched');
    },
];
