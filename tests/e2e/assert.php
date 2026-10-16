<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * End-to-end checks, run inside WordPress as `wp iim-e2e <scenario> [arg]` (registered by the e2e
 * mu-plugin). Each scenario throws on the first failed expectation (wp exits non-zero) and prints what
 * it verified.
 */

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Plugin;
use IdempotentImporter\Services;
use IdempotentImporter\Source\BigCommerce\BigCommerceAdapter;
use IdempotentImporter\Source\Etsy\EtsyAdapter;
use IdempotentImporter\Source\Shopify\ShopifyAdapter;
use IdempotentImporter\Source\SquareOnline\SquareOnlineAdapter;
use IdempotentImporter\Store\RunRecord;
use IdempotentImporter\Wp\Options;
use IdempotentImporter\Wp\Scheduler;
use IdempotentImporter\Wp\Storefront;
use IdempotentImporter\Wp\WebhookController;

/** WordPress + WooCommerce tables holding site content; the plugin's own bookkeeping is excluded. */
const CONTENT_TABLES = [
    'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'usermeta',
    'comments', 'commentmeta', 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data',
    'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_product_meta_lookup', 'wc_product_attributes_lookup',
    'woocommerce_shipping_zones', 'woocommerce_shipping_zone_locations', 'woocommerce_shipping_zone_methods',
    'woocommerce_attribute_taxonomies',
];
const WEBHOOK_SECRET = 'e2e-webhook-secret';

function expect(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException("FAILED: {$what}");
    }
    WP_CLI::log("ok - {$what}");
}

function services(): Services
{
    return new Services(Plugin::db());
}

function target(Kind $kind, string $gid): int
{
    $id = services()->links()->targetId($kind, $gid);
    expect($id !== null, "{$gid} is linked");

    return (int) $id;
}

function product(string $gid): WC_Product
{
    $product = wc_get_product(target(Kind::Product, $gid));

    return $product instanceof WC_Product ? $product : throw new RuntimeException("FAILED: {$gid} is not a product");
}

function order(Kind $kind, string $gid): WC_Order
{
    $order = wc_get_order(target($kind, $gid));

    return $order instanceof WC_Order ? $order : throw new RuntimeException("FAILED: {$gid} is not an order");
}

function user(string $gid): WP_User
{
    $user = get_user_by('id', target(Kind::Customer, $gid));

    return $user instanceof WP_User ? $user : throw new RuntimeException("FAILED: {$gid} is not a user");
}

function money(float|string $amount): string
{
    return wc_format_decimal((string) $amount, 2);
}

function latestRun(): RunRecord
{
    return services()->runLog()->recent(1)[0] ?? throw new RuntimeException('FAILED: no run recorded');
}

function checksum(): string
{
    $db = Plugin::db();
    $sums = [];
    foreach (CONTENT_TABLES as $table) {
        $row = $db->get_row($db->prepare('CHECKSUM TABLE %i', $db->prefix . $table), ARRAY_A);
        $sums[$table] = is_array($row) && is_scalar($row['Checksum'] ?? null) ? (string) $row['Checksum'] : 'missing';
    }

    return (string) json_encode($sums);
}

function configure(): void
{
    update_option(Settings::OPTION, [
        'store_domain' => 'fixture-boutique.myshopify.com',
        'store_settings' => true,
        'shipping_zones' => true,
        'interval_minutes' => 60,
    ], false);
    WP_CLI::log('configured');
}

function storeV1(): void
{
    $run = latestRun();
    expect($run->status === 'complete', 'the v1 run completed: ' . str_replace("\n", '; ', $run->message));

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
}

function noMail(): void
{
    expect(Options::int('iim_e2e_mail_sent') === 0, 'no email reached the mailer during the imports');
}

function storeV2(): void
{
    $run = latestRun();
    expect($run->status === 'complete', 'the v2 run completed: ' . str_replace("\n", '; ', $run->message));
    $scarf = product('gid://shopify/Product/301');
    expect($scarf->get_sale_price() === '55' && $scarf->get_stock_quantity() === 6, 'price and stock updated');
    expect(services()->links()->targetId(Kind::Product, 'gid://shopify/Product/302') === null, 'deleted product unlinked');
    expect(count(get_posts(['post_type' => 'product', 'post_status' => 'trash', 'fields' => 'ids'])) === 1, 'deleted product trashed, not deleted');
    order(Kind::Order, 'gid://shopify/Order/803');
    expect(services()->links()->targetId(Kind::DraftOrder, 'gid://shopify/DraftOrder/901') === null, 'completed draft retired');
    expect($run->counts->writes() > 0, 'the v2 run wrote the changes');
}

function zeroWrites(string $before): void
{
    $run = latestRun();
    expect($run->status === 'complete', 'the re-run completed');
    expect($run->counts->writes() === 0, 're-run reports zero writes: ' . str_replace("\n", '; ', $run->message));
    expect(checksum() === $before, 'every content table is byte-identical after the re-run');
}

function webhook(): void
{
    update_option(Credentials::OPTION, [ShopifyAdapter::WEBHOOK_SECRET => WEBHOOK_SECRET], false);
    $body = (string) json_encode(['id' => 301]);
    $request = new WP_REST_Request('POST', '/' . WebhookController::NAMESPACE . WebhookController::ROUTE);
    $request->set_body($body);
    $request->set_header('x-shopify-topic', 'products/update');
    $request->set_header('x-shopify-hmac-sha256', base64_encode(hash_hmac('sha256', $body, WEBHOOK_SECRET, true)));
    expect(rest_do_request($request)->get_status() === 200, 'signed webhook accepted');
    $forged = clone $request;
    $forged->set_header('x-shopify-hmac-sha256', base64_encode('forged'));
    expect(rest_do_request($forged)->get_status() === 401, 'forged webhook rejected');
    expect(as_has_scheduled_action(Scheduler::OBJECT_HOOK, ['product', 'gid://shopify/Product/301', false], Scheduler::GROUP), 'object sync queued');
}

/** @return list<WP_Comment> a product's approved reviews */
function reviews(int $productId): array
{
    $comments = get_comments(['post_id' => $productId, 'type' => 'review', 'status' => 'approve']);

    return is_array($comments) ? array_values(array_filter($comments, static fn(mixed $comment): bool => $comment instanceof WP_Comment)) : [];
}

/** Switches the site to the Etsy source, as if the operator had saved the app and connected the shop. */
function etsyConfigure(): void
{
    $settings = Options::array(Settings::OPTION);
    $settings['source'] = EtsyAdapter::ID;
    update_option(Settings::OPTION, $settings, false);
    Credentials::save(EtsyAdapter::REFRESH_TOKEN, 'e2e-refresh-token');
    WP_CLI::log('configured for Etsy');
}

/** The Shopify mirror made earlier is untouched by Etsy runs: a source only ever retires its own copies. */
function shopifyIntact(): void
{
    $scarf = product('gid://shopify/Product/301');
    expect($scarf->get_status() === 'publish', 'the Shopify scarf is still published after Etsy runs');
    expect(order(Kind::Order, 'gid://shopify/Order/801')->get_status() === 'completed', 'the Shopify order is untouched by Etsy runs');
}

function etsyV1(): void
{
    $run = latestRun();
    expect($run->status === 'complete', 'the Etsy v1 run completed: ' . str_replace("\n", '; ', $run->message));

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
    shopifyIntact();
}

function etsyV2(): void
{
    $run = latestRun();
    expect($run->status === 'complete', 'the Etsy v2 run completed: ' . str_replace("\n", '; ', $run->message));
    expect(product('gid://etsy/Listing/601')->get_regular_price() === '34', 'price updated');
    expect(services()->links()->targetId(Kind::Product, 'gid://etsy/Listing/603') === null, 'removed listing unlinked');
    expect(count(get_posts(['post_type' => 'product', 'post_status' => 'trash', 'fields' => 'ids'])) === 2, 'removed listing trashed beside the earlier Shopify deletion');
    $refunded = order(Kind::Order, 'gid://etsy/Receipt/801');
    expect(count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '10.00', 'partial refund mirrored');
    expect(order(Kind::Order, 'gid://etsy/Receipt/803')->get_status() === 'processing', 'new paid receipt → processing order');
    expect(count(reviews(product('gid://etsy/Listing/602')->get_id())) === 1, 'new review on the apron');
    expect($run->counts->writes() > 0, 'the Etsy v2 run wrote the changes');
    shopifyIntact();
}

/** Switches the site to the Square Online source (its access token comes from wp-config). */
function squareConfigure(): void
{
    $settings = Options::array(Settings::OPTION);
    $settings['source'] = SquareOnlineAdapter::ID;
    update_option(Settings::OPTION, $settings, false);
    WP_CLI::log('configured for Square Online');
}

/** The Etsy mirror made earlier is untouched by Square runs. */
function etsyIntact(): void
{
    expect(product('gid://etsy/Listing/601')->get_status() === 'publish', 'the Etsy mug is still published after Square runs');
    expect(order(Kind::Order, 'gid://etsy/Receipt/801')->get_status() === 'completed', 'the Etsy receipt is untouched by Square runs');
}

function squareV1(): void
{
    $run = latestRun();
    expect($run->status === 'complete', 'the Square v1 run completed: ' . str_replace("\n", '; ', $run->message));

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
    shopifyIntact();
    etsyIntact();
}

function squareV2(): void
{
    $run = latestRun();
    expect($run->status === 'complete', 'the Square v2 run completed: ' . str_replace("\n", '; ', $run->message));
    expect(product('gid://square_online/Item/ITEM_MUG')->get_regular_price() === '34', 'price updated');
    expect(services()->links()->targetId(Kind::Product, 'gid://square_online/Item/ITEM_SAMPLE') === null, 'deleted item unlinked');
    expect(count(get_posts(['post_type' => 'product', 'post_status' => 'trash', 'fields' => 'ids'])) === 3, 'deleted item trashed beside the earlier Shopify and Etsy deletions');
    $refunded = order(Kind::Order, 'gid://square_online/Order/ORDER_1');
    expect(count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '10.00', 'partial refund mirrored');
    $new = order(Kind::Order, 'gid://square_online/Order/ORDER_3');
    expect($new->get_status() === 'processing' && $new->get_customer_id() === user('gid://square_online/Customer/CUST_GRACE')->ID, 'new paid order → processing, linked to the new customer');
    expect($run->counts->writes() > 0, 'the Square v2 run wrote the changes');
    shopifyIntact();
    etsyIntact();
}

/** Switches the site to the BigCommerce source (its store hash and access token come from wp-config). */
function bigcommerceConfigure(): void
{
    $settings = Options::array(Settings::OPTION);
    $settings['source'] = BigCommerceAdapter::ID;
    update_option(Settings::OPTION, $settings, false);
    WP_CLI::log('configured for BigCommerce');
}

/** The Square mirror made earlier is untouched by BigCommerce runs. */
function squareIntact(): void
{
    expect(product('gid://square_online/Item/ITEM_MUG')->get_status() === 'publish', 'the Square mug is still published after BigCommerce runs');
    expect(order(Kind::Order, 'gid://square_online/Order/ORDER_1')->get_status() === 'completed', 'the Square order is untouched by BigCommerce runs');
}

/** Every other source's mirror is untouched by BigCommerce runs. */
function othersIntact(): void
{
    shopifyIntact();
    etsyIntact();
    squareIntact();
}

function bigcommerceV1(): void
{
    $run = latestRun();
    expect($run->status === 'complete', 'the BigCommerce v1 run completed: ' . str_replace("\n", '; ', $run->message));
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
    othersIntact();
}

function bigcommerceV2(): void
{
    $run = latestRun();
    expect($run->status === 'complete', 'the BigCommerce v2 run completed: ' . str_replace("\n", '; ', $run->message));
    expect(product('gid://bigcommerce/Product/111')->get_regular_price() === '26', 'price updated');
    expect(services()->links()->targetId(Kind::Product, 'gid://bigcommerce/Product/113') === null, 'deleted product unlinked');
    expect(count(get_posts(['post_type' => 'product', 'post_status' => 'trash', 'fields' => 'ids'])) === 4, 'deleted product trashed beside the earlier deletions');
    $refunded = order(Kind::Order, 'gid://bigcommerce/Order/501');
    expect($refunded->get_status() === 'completed' && count($refunded->get_refunds()) === 1 && money($refunded->get_total_refunded()) === '10.00', 'partial refund mirrored');
    $new = order(Kind::Order, 'gid://bigcommerce/Order/503');
    expect($new->get_status() === 'processing' && $new->get_customer_id() === user('gid://bigcommerce/Customer/43')->ID, 'new order awaiting fulfillment → processing, linked to the new customer');
    target(Kind::Article, 'gid://bigcommerce/Post/92');
    expect(count(reviews(product('gid://bigcommerce/Product/112')->get_id())) === 1, 'new approved review on the toque');
    expect($run->counts->writes() > 0, 'the BigCommerce v2 run wrote the changes');
    othersIntact();
}

/** @param array<array-key, mixed> $arguments */
function argument(array $arguments, int $index): string
{
    $value = $arguments[$index] ?? '';

    return is_string($value) ? $value : '';
}

/** @param array<array-key, mixed> $arguments */
function scenario(array $arguments): void
{
    $scenario = argument($arguments, 0);
    match ($scenario) {
        'configure' => configure(),
        'checksum' => file_put_contents(argument($arguments, 1), checksum()),
        'v1' => storeV1(),
        'v2' => storeV2(),
        'zero-writes' => zeroWrites((string) file_get_contents(argument($arguments, 1))),
        'webhook' => webhook(),
        'no-mail' => noMail(),
        'etsy-configure' => etsyConfigure(),
        'etsy-v1' => etsyV1(),
        'etsy-v2' => etsyV2(),
        'square-configure' => squareConfigure(),
        'square-v1' => squareV1(),
        'square-v2' => squareV2(),
        'bigcommerce-configure' => bigcommerceConfigure(),
        'bigcommerce-v1' => bigcommerceV1(),
        'bigcommerce-v2' => bigcommerceV2(),
        default => throw new RuntimeException("Unknown scenario {$scenario}"),
    };
}

WP_CLI::add_command('iim-e2e', scenario(...));
