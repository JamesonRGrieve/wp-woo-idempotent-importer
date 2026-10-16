<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\BigCommerce\BigCommerceReader;
use IdempotentImporter\Source\BigCommerce\Mapping\ArticleMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\BlogMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\CategoryMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\CustomerMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\DiscountMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\Fields;
use IdempotentImporter\Source\BigCommerce\Mapping\OrderMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\PageMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\ProductMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\ReviewMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\ShopMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\UrlRedirectMapper;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class BigCommerceMapperTest extends TestCase
{
    private const MEDIA = 'http://bigcommerce-fake:8080';

    public function testShop(): void
    {
        $shop = Values::of((new ShopMapper())->map($this->reader('v1')->shop()));

        self::assertSame('Northern Trail Outfitters', $shop->string('name'));
        self::assertSame('orders@northerntrail.example', $shop->nullableString('email'), 'the order email before the admin email');
        self::assertSame('CAD', $shop->string('currency'));
        self::assertSame('kg', $shop->nullableString('weight_unit'), 'KGS → kg');
        self::assertSame('America/Edmonton', $shop->nullableString('timezone'));
        self::assertFalse($shop->bool('prices_include_tax'));
        self::assertNull($shop->record('address'), 'the free-text address leaves WooCommerce\'s address settings alone');
        self::assertSame("200 Trail Road\nCanmore, AB T1W 1A1\nCanada", $shop->record('store')?->nullableString('address'), 'kept as meta');
        self::assertSame([], $shop->records('policies'));
    }

    public function testWeightUnits(): void
    {
        self::assertSame('lbs', Fields::weightUnit('LBS'));
        self::assertSame('lbs', Fields::weightUnit('Pounds'));
        self::assertSame('oz', Fields::weightUnit('Ounces'));
        self::assertSame('g', Fields::weightUnit('Grams'));
        self::assertSame('kg', Fields::weightUnit('KGS'));
        self::assertNull(Fields::weightUnit('Tonnes'), 'WooCommerce has no tonnes');
        self::assertNull(Fields::weightUnit(null));
    }

    public function testCategories(): void
    {
        [$kitchen, $headwear] = $this->mapAll('v1', Kind::Collection, new CategoryMapper());

        self::assertSame('Camp Kitchen', $kitchen->string('name'));
        self::assertSame('camp-kitchen', $kitchen->string('slug'), 'the slug its storefront URL ends in');
        self::assertSame('<p>Everything for cooking outdoors.</p>', $kitchen->nullableString('description_html'));
        self::assertSame(['url' => self::MEDIA . '/img/cat-kitchen.png', 'alt' => null], $kitchen->record('image')?->all());
        self::assertSame(['title' => 'Camp Kitchen | Northern Trail', 'description' => 'Shop camp kitchen.'], $kitchen->seo());
        self::assertNull($kitchen->nullableString('parent_gid'));
        self::assertNull($headwear->record('image'), 'no image URL, no image');
        self::assertSame(['/camp-kitchen'], $this->desired('v1', Kind::Collection, new CategoryMapper())[0]->legacyPaths);
    }

    public function testProducts(): void
    {
        $desired = $this->desired('v1', Kind::Product, new ProductMapper(false));
        [$mug, $toque, $lantern] = array_map(Values::of(...), $desired);

        self::assertSame('Enamel trail mug', $mug->string('title'));
        self::assertSame('enamel-trail-mug', $mug->string('slug'));
        self::assertSame('<p>A tough enamel mug for the trail.</p>', $mug->string('description_html'));
        self::assertSame('publish', $mug->string('status'));
        self::assertSame('2026-09-01T09:00:00Z', $mug->nullableString('created_at'));
        self::assertSame(['gid://bigcommerce/Category/21'], $mug->strings('collections'));
        self::assertSame('Northern Trail', $mug->nullableString('brand'));
        self::assertSame(
            [['url' => self::MEDIA . '/img/mug-zoom.png', 'alt' => 'Enamel trail mug'], ['url' => self::MEDIA . '/img/mug-side-zoom.png', 'alt' => null]],
            $mug->raw('images'),
            'the thumbnail first, then by sort order, at the largest size',
        );
        self::assertSame(['/enamel-trail-mug'], $desired[0]->legacyPaths, 'the old storefront URL redirects');
        self::assertSame([['name' => 'Material', 'value' => 'Enamel steel']], $mug->raw('custom_fields'));
        self::assertSame(['title' => 'Enamel trail mug | Northern Trail', 'description' => 'Enamel trail mug from Northern Trail.'], $mug->seo());
        self::assertTrue($mug->bool('simple'), 'one variant with no option values: the base variant');
        $base = $mug->records('variants')[0];
        self::assertSame('gid://bigcommerce/Variant/1110', $base->string('gid'));
        self::assertSame('BC-MUG-1', $base->nullableString('sku'));
        self::assertSame('0778899100018', $base->nullableString('gtin'), "the product's GTIN for its base variant");
        self::assertSame('24', $base->string('regular_price'), "an unset variant price is the product's");
        self::assertNull($base->nullableString('sale_price'), 'a zero sale price is no sale');
        self::assertNull($base->nullableString('cost'), 'a zero cost is no cost');
        self::assertSame(['value' => '0.4', 'unit' => 'kg'], $base->record('weight')?->all(), 'in the store unit');
        self::assertTrue($base->bool('manage_stock'));
        self::assertSame(12, $base->nullableInt('stock_quantity'), 'product-level tracking on a simple product');
        self::assertFalse($base->bool('virtual'));

        self::assertFalse($toque->bool('simple'));
        self::assertSame([['name' => 'Colour', 'position' => 1, 'values' => ['Red', 'Navy']]], $toque->raw('attributes'));
        self::assertSame(['gid://bigcommerce/Category/22', 'gid://bigcommerce/Category/21'], $toque->strings('collections'));
        [$red, $navy] = $toque->records('variants');
        self::assertSame(['Colour' => 'Red'], $red->stringMap('options'));
        self::assertSame('Red', $red->string('title'));
        self::assertSame('30', $red->string('regular_price'));
        self::assertSame(5, $red->nullableInt('stock_quantity'));
        self::assertSame(['value' => '0.15', 'unit' => 'kg'], $red->record('weight')?->all(), "the product's weight");
        self::assertNull($red->nullableString('image'));
        self::assertSame('32.5', $navy->string('regular_price'), 'its own price, sent as a JSON number');
        self::assertSame(0, $navy->nullableInt('stock_quantity'));
        self::assertSame(self::MEDIA . '/img/toque-navy.png', $navy->nullableString('image'));
        self::assertSame('0778899100025', $navy->nullableString('gtin'));

        self::assertSame('private', $lantern->string('status'), 'a hidden product is kept, private');
        $only = $lantern->records('variants')[0];
        self::assertFalse($only->bool('manage_stock'), 'no inventory tracking');
        self::assertNull($only->nullableInt('stock_quantity'));
        self::assertNull($only->nullableString('sku'));
        self::assertNull($only->record('weight'), 'a zero weight is no weight');
        self::assertSame(['"Prototype lantern" has 1 variant(s) without a SKU; WooCommerce Square needs one on each.'], $desired[2]->warnings);
        self::assertSame('bigcommerce-1130', Values::of((new ProductMapper(true))->map($this->nodes('v1', Kind::Product)[2]))->records('variants')[0]->nullableString('sku'));
    }

    public function testPriceChangesAndVariantPriceFallbacks(): void
    {
        [$mug] = $this->mapAll('v2', Kind::Product, new ProductMapper(false));
        self::assertSame('26', $mug->records('variants')[0]->string('regular_price'));

        $product = $this->nodes('v1', Kind::Product)[1];
        $raw = $product->raw();
        $variants = is_array($raw['variants'] ?? null) ? $raw['variants'] : [];
        $onSale = [
            ...$raw,
            'sale_price' => '27.5000',
            'variants' => [[...(is_array($variants[0] ?? null) ? $variants[0] : []), 'sale_price' => 0.1, 'cost_price' => 1e2], ...array_slice($variants, 1)],
        ];
        [$red, $navy] = Values::of((new ProductMapper(false))->map(new Node($onSale, $product->id())))->records('variants');
        self::assertSame('0.1', $red->nullableString('sale_price'), 'a variant sale price is read exactly');
        self::assertSame('100', $red->nullableString('cost'), 'an exponent is expanded exactly');
        self::assertSame('27.5', $navy->nullableString('sale_price'), "an unset variant sale price is the product's");
    }

    public function testAVariableProductTrackedAsAWholeWarns(): void
    {
        $product = $this->nodes('v1', Kind::Product)[1];

        $desired = (new ProductMapper(false))->map(new Node([...$product->raw(), 'inventory_tracking' => 'product'], $product->id()));

        self::assertFalse(Values::of($desired)->records('variants')[0]->bool('manage_stock'));
        self::assertContains('"Wool toque" tracks stock for the whole product; its variations are mirrored untracked (the product\'s stock is meta).', $desired->warnings);
    }

    public function testCustomers(): void
    {
        [$ingrid, $tomas] = $this->mapAll('v1', Kind::Customer, new CustomerMapper());

        self::assertSame('ingrid@example.com', $ingrid->nullableString('email'));
        self::assertSame('bigcommerce-41', $ingrid->string('username'));
        self::assertSame('Prefers email.', $ingrid->nullableString('note'));
        self::assertSame('2026-09-01T10:00:00Z', $ingrid->nullableString('created_at'));
        self::assertSame([
            'first_name' => 'Ingrid', 'last_name' => 'Johansson', 'company' => 'Trail Co-op', 'address_1' => '12 Spruce Lane',
            'city' => 'Canmore', 'state' => 'Alberta', 'postcode' => 'T1W 2B2', 'country' => 'CA', 'phone' => '+1 403-555-0141',
        ], $ingrid->record('billing')?->all(), "the first saved address, with the customer's phone");
        self::assertSame($ingrid->raw('billing'), $ingrid->raw('shipping'));
        self::assertNull($tomas->record('billing'), 'no saved address');
        self::assertSame(1, $ingrid->record('customer')?->int('origin_channel_id'));
    }

    public function testCoupons(): void
    {
        $nodes = $this->nodes('v1', Kind::Discount);
        $mapper = new DiscountMapper();
        $welcome = Values::of($mapper->map($nodes[0]) ?? self::fail('the welcome coupon maps'));
        $shipping = Values::of($mapper->map($nodes[1]) ?? self::fail('the free-shipping coupon maps'));

        self::assertSame('Welcome', $welcome->string('title'));
        self::assertSame(['BC-WELCOME'], $welcome->strings('codes'));
        self::assertSame('fixed_cart', $welcome->string('discount_type'), 'an amount off the order');
        self::assertSame('4.5', $welcome->string('amount'));
        self::assertSame('50', $welcome->nullableString('minimum_subtotal'));
        self::assertSame(100, $welcome->nullableInt('usage_limit'));
        self::assertTrue($welcome->bool('once_per_customer'));
        self::assertSame('2026-09-01T10:00:00Z', $welcome->string('starts_at'));
        self::assertSame('2026-12-31T23:59:00Z', $welcome->nullableString('expires_at'), 'an RFC 2822 expiry');
        self::assertSame([], $welcome->strings('collection_gids'), 'category 0 is the whole store');
        self::assertTrue($shipping->bool('free_shipping'));
        self::assertSame('0', $shipping->string('amount'));
        self::assertNull($shipping->nullableInt('usage_limit'), '0 uses is unlimited');
        self::assertNull($mapper->map($nodes[2]), 'a disabled coupon maps to nothing, so its code is retired');
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('shipping_discount');
        $mapper->map($nodes[3]);
    }

    public function testCouponsLimitedToProductsOrCategories(): void
    {
        $coupon = $this->nodes('v1', Kind::Discount)[2];
        $percent = new Node([...$coupon->raw(), 'enabled' => true], $coupon->id());
        $byCategory = new Node([...$coupon->raw(), 'enabled' => true, 'type' => 'per_item_discount', 'applies_to' => ['entity' => 'categories', 'ids' => [22]]], $coupon->id());
        $orderWide = new Node([...$coupon->raw(), 'enabled' => true, 'type' => 'per_total_discount'], $coupon->id());

        $toques = Values::of((new DiscountMapper())->map($percent) ?? self::fail('maps'));
        self::assertSame('percent', $toques->string('discount_type'));
        self::assertSame('20', $toques->string('amount'));
        self::assertSame(['gid://bigcommerce/Product/112'], $toques->strings('product_gids'));
        $headwear = Values::of((new DiscountMapper())->map($byCategory) ?? self::fail('maps'));
        self::assertSame('fixed_product', $headwear->string('discount_type'), 'an amount off each item');
        self::assertSame(['gid://bigcommerce/Category/22'], $headwear->strings('collection_gids'));
        $this->expectException(Unsupported::class);
        (new DiscountMapper())->map($orderWide);
    }

    public function testCouponPromotions(): void
    {
        $nodes = $this->nodes('v1', Kind::Discount);
        $fall = Values::of((new DiscountMapper())->map($nodes[4]) ?? self::fail('the coupon promotion maps'));

        self::assertSame('Fall 15% off', $fall->string('title'));
        self::assertSame(['BC-FALL15'], $fall->strings('codes'));
        self::assertSame('percent', $fall->string('discount_type'));
        self::assertSame('15', $fall->string('amount'));
        self::assertSame('40', $fall->nullableString('minimum_subtotal'), 'the minimum-spend condition');
        self::assertTrue($fall->bool('once_per_customer'));
        self::assertSame('2026-09-01T00:00:00Z', $fall->string('starts_at'));
        self::assertSame('2026-12-31T23:59:59Z', $fall->nullableString('expires_at'));
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('automatic promotion');
        (new DiscountMapper())->map($nodes[5]);
    }

    public function testPromotionsWooCommerceCannotApplyAreUnsupported(): void
    {
        $promotion = $this->nodes('v1', Kind::Discount)[4];
        $raw = $promotion->raw();
        $rule = $promotion->list('rules')[0]->raw();
        $variants = [
            'item discount' => ['rules' => [['action' => ['cart_items' => ['discount' => ['percentage_amount' => '10']]]]]],
            'customer group' => ['customer' => ['group_ids' => [2], 'minimum_order_count' => 0, 'excluded_group_ids' => []]],
            'capped' => ['rules' => [['action' => ['cart_value' => ['discount' => ['percentage_amount' => '10'], 'maximum_allowed_discount_amount' => '20']]]]],
            'item condition' => ['rules' => [['action' => ['cart_value' => ['discount' => ['fixed_amount' => '5']]], 'condition' => ['cart' => ['items' => ['brands' => [5]]]]]]],
            'two rules' => ['rules' => [$rule, $rule]],
        ];
        $unsupported = [];
        foreach ($variants as $case => $change) {
            try {
                (new DiscountMapper())->map(new Node([...$raw, ...$change], $promotion->id()));
            } catch (Unsupported) {
                $unsupported[] = $case;
            }
        }
        self::assertSame(array_keys($variants), $unsupported);
        self::assertNull((new DiscountMapper())->map(new Node([...$raw, 'status' => 'DISABLED'], $promotion->id())), 'a disabled promotion maps to nothing');
    }

    public function testOrders(): void
    {
        [$shipped, $pending] = $this->mapAll('v1', Kind::Order, new OrderMapper());
        $products = $this->mapAll('v1', Kind::Product, new ProductMapper(false));

        self::assertSame('#501', $shipped->string('name'));
        self::assertSame('2026-09-01T16:00:00Z', $shipped->string('created_at'), 'RFC 2822 → ISO 8601 UTC');
        self::assertSame('CAD', $shipped->string('currency'));
        self::assertFalse($shipped->bool('prices_include_tax'));
        self::assertSame('gid://bigcommerce/Customer/41', $shipped->nullableString('customer_gid'));
        self::assertSame('ingrid@example.com', $shipped->nullableString('email'));
        self::assertSame('completed', $shipped->string('status'), 'Shipped');
        self::assertSame([
            'first_name' => 'Ingrid', 'last_name' => 'Johansson', 'address_1' => '12 Spruce Lane', 'city' => 'Canmore',
            'state' => 'Alberta', 'postcode' => 'T1W 2B2', 'country' => 'CA', 'phone' => '+1 403-555-0141',
        ], $shipped->record('billing')?->all());
        self::assertSame('T1W 2B2', $shipped->record('shipping')?->nullableString('postcode'));
        self::assertSame(['BC-WELCOME'], $shipped->strings('coupons'));
        [$mugLine, $toqueLine] = $shipped->records('line_items');
        self::assertSame('gid://bigcommerce/OrderProduct/5011', $mugLine->string('gid'));
        self::assertSame('gid://bigcommerce/Product/111', $mugLine->nullableString('product_gid'));
        self::assertSame($products[0]->records('variants')[0]->string('gid'), $mugLine->nullableString('variant_gid'), "a simple product's line points at its base variant");
        self::assertSame($products[1]->records('variants')[1]->string('gid'), $toqueLine->nullableString('variant_gid'), 'a line points at the variant the product mapped');
        self::assertSame('48', $mugLine->string('subtotal'), '2 × 24, before the coupon');
        self::assertSame('44', $mugLine->string('total'), 'after its share of the coupon');
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'amount' => '2.2']], $mugLine->raw('taxes'));
        self::assertSame('BC-MUG-1', $mugLine->nullableString('sku'));
        self::assertSame(2, $mugLine->int('quantity'));
        self::assertSame(['Colour' => 'Navy'], $toqueLine->stringMap('attributes'));
        self::assertSame('32', $toqueLine->string('total'));
        self::assertSame(
            [['title' => 'Canada Post Expedited', 'code' => null, 'source' => 'bigcommerce', 'total' => '12', 'taxes' => [['title' => 'GST', 'rate' => '0.05', 'amount' => '0.6']]]],
            $shipped->raw('shipping_lines'),
        );
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'order_amount' => null]], $shipped->raw('tax_lines'));
        self::assertSame('4.5', $shipped->string('discount_total'));
        self::assertSame('12', $shipped->string('shipping_total'));
        self::assertSame('4.4', $shipped->string('tax_total'));
        self::assertSame('92.4', $shipped->string('total'));
        self::assertSame([], $shipped->raw('fees'));
        self::assertSame('2026-09-01T16:00:00Z', $shipped->nullableString('processed_at'), 'captured');
        self::assertSame('2026-09-03T11:00:00Z', $shipped->nullableString('closed_at'));
        self::assertSame('Please leave it at the back door.', $shipped->nullableString('customer_note'));
        self::assertSame('Credit Card', $shipped->nullableString('payment_method_title'));
        self::assertSame([
            [
                'gid' => 'gid://bigcommerce/Shipment/1',
                'created_at' => '2026-09-03T11:00:00Z',
                'author' => null,
                'message' => 'BigCommerce shipment: Canada Post CP123456789CA https://www.canadapost-postescanada.ca/track/CP123456789CA',
            ],
            ['gid' => 'gid://bigcommerce/StaffNote/501', 'created_at' => '2026-09-01T16:00:00Z', 'author' => 'Staff', 'message' => 'Gift for her brother.'],
        ], $shipped->raw('notes'));
        self::assertSame('captured', $shipped->record('order')?->nullableString('payment_status'));

        self::assertSame('pending', $pending->string('status'));
        self::assertNull($pending->nullableString('customer_gid'), 'a guest order');
        self::assertNull($pending->nullableString('processed_at'), 'not paid');
        self::assertSame('Pickup in store', $pending->records('shipping_lines')[0]->string('title'));
        self::assertSame('31.5', $pending->string('total'));
    }

    public function testRefundsAndNewOrders(): void
    {
        [$refunded, , $new] = $this->mapAll('v2', Kind::Order, new OrderMapper());

        self::assertSame('completed', $refunded->string('status'), 'partially refunded after shipping');
        self::assertSame([[
            'gid' => 'gid://bigcommerce/Refund/9001',
            'created_at' => '2026-09-10T15:00:00Z',
            'note' => 'Mug arrived dented',
            'amount' => '10',
            'lines' => [],
        ]], $refunded->raw('refunds'));
        self::assertSame('processing', $new->string('status'), 'Awaiting Fulfillment');
        self::assertSame('gid://bigcommerce/Customer/43', $new->nullableString('customer_gid'));
        self::assertSame('35.7', $new->string('total'));
    }

    public function testOrderStatusRules(): void
    {
        self::assertSame('pending', OrderMapper::status(1, false));
        self::assertSame('completed', OrderMapper::status(2, true));
        self::assertSame('processing', OrderMapper::status(3, true));
        self::assertSame('refunded', OrderMapper::status(4, true));
        self::assertSame('cancelled', OrderMapper::status(5, false));
        self::assertSame('failed', OrderMapper::status(6, false));
        self::assertSame('on-hold', OrderMapper::status(7, false));
        self::assertSame('processing', OrderMapper::status(11, false));
        self::assertSame('processing', OrderMapper::status(14, false), 'partially refunded before shipping');
        self::assertSame('completed', OrderMapper::status(14, true));
        self::assertSame('pending', OrderMapper::status(99, false), 'an unknown status');
    }

    public function testIncompleteAndArchivedOrdersAreNotMirrored(): void
    {
        $order = $this->nodes('v1', Kind::Order)[1];

        self::assertNull((new OrderMapper())->map(new Node([...$order->raw(), 'status_id' => 0], $order->id())), 'an abandoned checkout');
        self::assertNull((new OrderMapper())->map(new Node([...$order->raw(), 'is_deleted' => true], $order->id())), 'an archived order');
    }

    public function testAnOrderWithHandlingWrappingFeesAndOnlyAnOrderTaxTotal(): void
    {
        $order = $this->nodes('v1', Kind::Order)[1];
        $raw = $order->raw();
        $addresses = is_array($raw['shipping_addresses'] ?? null) ? $raw['shipping_addresses'] : [];
        $changed = new Node([
            ...$raw,
            'taxes' => [],
            'wrapping_cost_ex_tax' => '3.0000',
            'shipping_addresses' => [[...(is_array($addresses[0] ?? null) ? $addresses[0] : []), 'handling_cost_ex_tax' => '2.0000']],
            'fees' => [['id' => 1, 'type' => 'custom_fee', 'display_name_customer' => 'Bag fee', 'display_name_merchant' => 'Bag', 'source' => 'API', 'base_cost' => 0.25, 'cost_ex_tax' => 0.25, 'cost_inc_tax' => 0.25, 'cost_tax' => 0, 'tax_class_id' => null]],
        ], $order->id());

        $mapped = Values::of((new OrderMapper())->map($changed) ?? self::fail('maps'));

        self::assertSame([
            ['name' => 'Handling', 'total' => '2', 'taxes' => []],
            ['name' => 'Gift wrapping', 'total' => '3', 'taxes' => []],
            ['name' => 'Bag fee', 'total' => '0.25', 'taxes' => []],
        ], $mapped->raw('fees'));
        self::assertSame([['title' => 'Tax', 'rate' => null, 'order_amount' => '1.5']], $mapped->raw('tax_lines'), 'a tax reported only as the order total');
    }

    public function testPages(): void
    {
        $nodes = $this->nodes('v1', Kind::Page);
        $mapper = new PageMapper();
        $about = $mapper->map($nodes[0]);
        $sizes = Values::of($mapper->map($nodes[1]));

        self::assertSame('About us', Values::of($about)->string('title'));
        self::assertSame('about-us', Values::of($about)->string('slug'));
        self::assertSame('<p>Outfitting Canadian trails since 2019.</p>', Values::of($about)->string('content_html'));
        self::assertSame('publish', Values::of($about)->string('status'));
        self::assertNull(Values::of($about)->nullableString('published_at'), 'BigCommerce keeps no publication time');
        self::assertSame(['/about-us'], $about->legacyPaths);
        self::assertSame('draft', $sizes->string('status'), 'a hidden page');
        self::assertSame('raw', $sizes->record('page')?->string('type'));
        self::assertSame('private', Values::of($mapper->map(new Node([...$nodes[0]->raw(), 'is_customers_only' => true], $nodes[0]->id())))->string('status'));
        self::assertSame(
            ['title' => 'About', 'description' => 'Who we are'],
            Values::of($mapper->map(new Node([...$nodes[0]->raw(), 'meta_title' => 'About', 'meta_description' => 'Who we are'], $nodes[0]->id())))->seo(),
        );
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('contact_form');
        $mapper->map($nodes[2]);
    }

    public function testTheBlogAndItsPosts(): void
    {
        $blog = (new BlogMapper())->map($this->reader('v1')->page(Kind::Blog, null, null, '')->nodes[0]);
        [$post] = $this->desired('v1', Kind::Article, new ArticleMapper());
        $article = Values::of($post);

        self::assertSame('gid://bigcommerce/Blog/bcfix123', $blog->gid);
        self::assertSame('Trail Journal', Values::of($blog)->string('name'));
        self::assertSame('blog', Values::of($blog)->string('slug'));
        self::assertSame(['/blog'], $blog->legacyPaths);
        self::assertSame('Packing for a winter hike', $article->string('title'));
        self::assertSame('packing-for-a-winter-hike', $article->string('slug'));
        self::assertSame($blog->gid, $article->string('blog_gid'));
        self::assertSame('publish', $article->string('status'));
        self::assertSame('2026-09-03T09:00:00Z', $article->string('published_at'));
        self::assertSame('Ingrid Johansson', $article->nullableString('author'));
        self::assertSame(['winter', 'hiking'], $article->strings('tags'));
        self::assertSame('<p>Packing for a winter hike.</p>', $article->nullableString('excerpt_html'));
        self::assertSame(['url' => self::MEDIA . '/product_images/uploaded_images/winter-hike.png', 'alt' => 'Packing for a winter hike'], $article->record('image')?->all());
        self::assertSame(['/blog/packing-for-a-winter-hike'], $post->legacyPaths);
        $default = (new BlogMapper())->map(new Node(['id' => 'gid://bigcommerce/Blog/x', 'page' => null]));
        self::assertSame(['Blog', 'blog'], [Values::of($default)->string('name'), Values::of($default)->string('slug')], 'a store without a blog page');
    }

    public function testRedirects(): void
    {
        [$product, $external, $category] = $this->desired('v1', Kind::UrlRedirect, new UrlRedirectMapper());

        self::assertSame(['/old-trail-mug'], $product->legacyPaths);
        self::assertSame('/enamel-trail-mug', $product->redirectTarget, "to the product's own old path, which redirects to the mirrored product");
        self::assertSame('https://northerntrail.example/sale', $external->redirectTarget);
        self::assertSame('/camp-kitchen', $category->redirectTarget);
    }

    public function testReviews(): void
    {
        [$approved, $pending] = $this->mapAll('v1', Kind::Review, new ReviewMapper());

        self::assertSame('gid://bigcommerce/Product/111', $approved->nullableString('product_gid'));
        self::assertSame(5, $approved->int('rating'));
        self::assertSame("<strong>Great mug</strong>\n\nKeeps coffee hot on cold mornings.", $approved->string('content'));
        self::assertSame('Ingrid J.', $approved->string('author'));
        self::assertSame('ingrid@example.com', $approved->string('email'));
        self::assertSame('2026-09-02T12:00:00Z', $approved->string('created_at'));
        self::assertTrue($approved->bool('approved'));
        self::assertFalse($approved->bool('verified'));
        self::assertFalse($pending->bool('approved'), 'a pending review is kept unapproved');
        self::assertSame('gid://bigcommerce/Product/112', $pending->nullableString('product_gid'));
        self::assertCount(3, $this->mapAll('v2', Kind::Review, new ReviewMapper()), 'a new review');
    }

    public function testMoneyAndTimesAreExact(): void
    {
        $node = new Node(['a' => 19.99, 'b' => 0.1, 'c' => 1e2, 'd' => '225.0000', 'e' => '0.0000', 'when' => 'Tue, 01 Sep 2026 10:00:00 -0600']);

        self::assertSame('19.99', Fields::decimalOrZero($node, 'a'));
        self::assertSame('0.1', Fields::decimalOrZero($node, 'b'));
        self::assertSame('100', Fields::decimalOrZero($node, 'c'));
        self::assertSame('225', Fields::decimalOrZero($node, 'd'));
        self::assertSame('0', Fields::decimalOrZero($node, 'missing'));
        self::assertNull(Fields::setAmount($node, 'e'), 'zero is unset');
        self::assertSame('2026-09-01T16:00:00Z', Fields::time($node, 'when'), 'an RFC 2822 time with an offset');
        self::assertSame('trail-mug', Fields::slug('https://northerntrail.example/kitchen/trail-mug/', 'Ignored'));
        self::assertSame('a-title', Fields::slug('/', 'A title'));
    }

    /** @return list<Values> */
    private function mapAll(string $state, Kind $kind, Mapper $mapper, ?int $limit = null): array
    {
        return array_map(Values::of(...), $this->desired($state, $kind, $mapper, $limit));
    }

    /** @return list<Desired> */
    private function desired(string $state, Kind $kind, Mapper $mapper, ?int $limit = null): array
    {
        return array_map(
            static fn(Node $node): Desired => $mapper->map($node) ?? self::fail('mapped to nothing'),
            array_slice($this->nodes($state, $kind), 0, $limit),
        );
    }

    /** @return list<Node> */
    private function nodes(string $state, Kind $kind): array
    {
        $reader = $this->reader($state);
        $nodes = [];
        $cursor = null;
        do {
            $page = $reader->page($kind, $cursor, null, '');
            $nodes = [...$nodes, ...$page->nodes];
            $cursor = $page->endCursor;
        } while ($page->hasNextPage);

        return $nodes;
    }

    private function reader(string $state): BigCommerceReader
    {
        return BigCommerceReader::connect('https://api.bigcommerce.test', 'bcfix123', 'fixture-access-token', new ScriptedTransport(new FixtureResponder('bigcommerce', $state)), new RecordingSleeper());
    }
}
