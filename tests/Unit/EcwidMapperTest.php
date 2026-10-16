<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Ecwid\EcwidReader;
use IdempotentImporter\Source\Ecwid\Mapping\CategoryMapper;
use IdempotentImporter\Source\Ecwid\Mapping\CustomerMapper;
use IdempotentImporter\Source\Ecwid\Mapping\DiscountMapper;
use IdempotentImporter\Source\Ecwid\Mapping\Fields;
use IdempotentImporter\Source\Ecwid\Mapping\OrderMapper;
use IdempotentImporter\Source\Ecwid\Mapping\ProductMapper;
use IdempotentImporter\Source\Ecwid\Mapping\ReviewMapper;
use IdempotentImporter\Source\Ecwid\Mapping\ShopMapper;
use IdempotentImporter\Source\Ecwid\Mapping\UrlRedirectMapper;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class EcwidMapperTest extends TestCase
{
    private const MEDIA = 'http://ecwid-fake:8080/images';

    public function testShop(): void
    {
        $shop = Values::of((new ShopMapper())->map($this->reader('v1')->shop()));

        self::assertSame('Cedar & Wick Candle Co.', $shop->string('name'), "the store's name");
        self::assertSame('hello@cedarwick.example', $shop->nullableString('email'));
        self::assertSame('CAD', $shop->string('currency'));
        self::assertSame('kg', $shop->nullableString('weight_unit'));
        self::assertSame('America/Edmonton', $shop->nullableString('timezone'));
        self::assertFalse($shop->bool('prices_include_tax'), 'net prices');
        self::assertSame(
            ['address_1' => '88 Bow Valley Trail', 'address_2' => 'Unit 3', 'city' => 'Canmore', 'state' => 'AB', 'postcode' => 'T1W 1N8', 'country' => 'CA'],
            $shop->record('address')?->all(),
            "the street's two lines",
        );
        self::assertSame([
            ['gid' => 'gid://ecwid/Policy/7400100-TERMS', 'type' => 'TERMS', 'role' => 'terms', 'title' => 'Terms & Conditions', 'content_html' => '<p>Orders ship within three business days.</p>', 'legacy_paths' => []],
            ['gid' => 'gid://ecwid/Policy/7400100-PRIVACY_STATEMENT', 'type' => 'PRIVACY_STATEMENT', 'role' => 'privacy', 'title' => 'Privacy Statement', 'content_html' => '<p>We use your details only to fill your order.</p>', 'legacy_paths' => []],
        ], $shop->raw('policies'), 'the enabled inline legal pages, wired to the terms and privacy pages');
        $store = $shop->record('store') ?? self::fail('the store details are kept');
        self::assertSame('https://cedarwick.example', $store->string('storeUrl'));
        self::assertSame([['type' => 'LEGAL_INFO', 'title' => 'Company Info', 'url' => 'https://cedarwick.example/about']], $store->raw('external_legal_pages'), 'a legal page linking elsewhere');
    }

    public function testCategories(): void
    {
        $desired = $this->desired('v1', Kind::Collection, new CategoryMapper());
        [$candles, $melts] = array_map(Values::of(...), $desired);

        self::assertSame('Candles', $candles->string('name'));
        self::assertSame('candles', $candles->string('slug'), 'the generated slug when there is no custom one');
        self::assertSame('<p>Hand-poured soy candles.</p>', $candles->string('description_html'));
        self::assertSame(['url' => self::MEDIA . '/ecw-cat-candles.png', 'alt' => 'Candles on a cedar shelf'], $candles->record('image')?->all());
        self::assertSame(['title' => 'Soy candles | Cedar & Wick', 'description' => 'Hand-poured soy candles from Canmore.'], $candles->raw('seo'));
        self::assertNull($candles->nullableString('parent_gid'));
        self::assertSame(['/products/candles'], $desired[0]->legacyPaths);
        self::assertSame('wax-melts', $melts->string('slug'), 'the custom slug first');
        self::assertSame('gid://ecwid/Category/9101', $melts->nullableString('parent_gid'));
        self::assertNull($melts->record('image'));
        self::assertTrue($melts->record('category')?->bool('enabled'));
    }

    public function testProducts(): void
    {
        $desired = $this->desired('v1', Kind::Product, new ProductMapper(false));
        [$candle, $melts, $diffuser] = array_map(Values::of(...), $desired);

        self::assertSame('Cedar candle', $candle->string('title'));
        self::assertSame('cedar-candle', $candle->string('slug'));
        self::assertSame('<p>A hand-poured cedar and soy candle.</p>', $candle->string('description_html'));
        self::assertSame('publish', $candle->string('status'));
        self::assertSame('2026-09-01T09:00:00Z', $candle->nullableString('created_at'));
        self::assertSame(['gid://ecwid/Category/9101'], $candle->strings('collections'));
        self::assertSame('Cedarwick', $candle->nullableString('brand'), 'the brand attribute');
        self::assertSame(
            [['url' => self::MEDIA . '/ecw-cedar-candle.png', 'alt' => 'Cedar candle in an amber jar'], ['url' => self::MEDIA . '/ecw-cedar-candle-lid.png', 'alt' => null]],
            $candle->raw('images'),
            'the main image first, full size',
        );
        self::assertSame(['title' => 'Cedar candle | Cedar & Wick', 'description' => 'A hand-poured cedar and soy candle.'], $candle->raw('seo'));
        self::assertSame(['/products/cedar-candle'], $desired[0]->legacyPaths, 'the old storefront URL redirects');
        self::assertTrue($candle->bool('simple'), 'no variations');
        $only = $candle->records('variants')[0];
        self::assertSame('gid://ecwid/Variant/9201-base', $only->string('gid'));
        self::assertSame('Cedar candle', $only->string('title'));
        self::assertSame('ECW-CANDLE-1', $only->nullableString('sku'));
        self::assertSame('0774455660013', $only->nullableString('gtin'), 'the UPC attribute');
        self::assertSame('28', $only->string('regular_price'));
        self::assertNull($only->nullableString('sale_price'), 'no compare-to price, no sale');
        self::assertSame('9.5', $only->nullableString('cost'));
        self::assertSame(['value' => '0.42', 'unit' => 'kg'], $only->record('weight')?->all(), "in the store's unit");
        self::assertFalse($only->bool('virtual'));
        self::assertTrue($only->bool('taxable'));
        self::assertTrue($only->bool('manage_stock'));
        self::assertSame(12, $only->nullableInt('stock_quantity'));
        self::assertFalse($only->bool('backorders'));
        self::assertSame('Burns for 45 hours', $candle->record('product')?->string('subtitle'));
        self::assertSame([], $desired[0]->warnings);

        self::assertFalse($melts->bool('simple'));
        self::assertSame([['name' => 'Scent', 'position' => 1, 'values' => ['Fir', 'Birch']]], $melts->raw('attributes'));
        self::assertSame(['gid://ecwid/Category/9102', 'gid://ecwid/Category/9101'], $melts->strings('collections'));
        [$fir, $birch] = $melts->records('variants');
        self::assertSame('gid://ecwid/Variant/920201', $fir->string('gid'), 'a variation by its Ecwid id');
        self::assertSame(['Scent' => 'Fir'], $fir->stringMap('options'));
        self::assertSame('Fir', $fir->string('title'));
        self::assertSame('16', $fir->string('regular_price'), 'a compare-to price above the price is the regular price');
        self::assertSame('14', $fir->nullableString('sale_price'), '… and the price the sale price');
        self::assertSame('0774455660020', $fir->nullableString('gtin'));
        self::assertSame(20, $fir->nullableInt('stock_quantity'));
        self::assertTrue($fir->bool('backorders'), "the product's pre-orders");
        self::assertSame(self::MEDIA . '/ecw-melt-fir.png', $fir->nullableString('image'));
        self::assertNull($fir->nullableString('cost'), 'variations keep no cost price');
        self::assertSame('gid://ecwid/Variant/920202', $birch->string('gid'));
        self::assertSame(2, $birch->int('position'));
        self::assertFalse($birch->bool('manage_stock'), 'unlimited');
        self::assertNull($birch->nullableInt('stock_quantity'));
        self::assertSame(['value' => '0.15', 'unit' => 'kg'], $birch->record('weight')?->all(), "the product's weight when the variation sets none");
        self::assertSame('14', $birch->string('regular_price'));
        self::assertSame(3, $melts->record('variation_details')?->record('920201')?->int('warningLimit'));

        self::assertSame('private', $diffuser->string('status'), 'a disabled product is kept, private');
        $diffuserVariant = $diffuser->records('variants')[0];
        self::assertNull($diffuserVariant->nullableString('sku'));
        self::assertFalse($diffuserVariant->bool('manage_stock'));
        self::assertNull($diffuserVariant->record('weight'));
        self::assertSame([], $diffuser->raw('images'));
        self::assertNull($diffuser->nullableString('brand'));
        self::assertSame(['"Sample diffuser" has 1 variant(s) without a SKU; WooCommerce Square needs one on each.'], $desired[2]->warnings);
        self::assertSame('ecwid-9203', Values::of((new ProductMapper(true))->map($this->nodes('v1', Kind::Product)[2]))->records('variants')[0]->nullableString('sku'));
    }

    public function testPriceChanges(): void
    {
        [$candle] = $this->mapAll('v2', Kind::Product, new ProductMapper(false));

        self::assertSame('29.5', $candle->records('variants')[0]->string('regular_price'));
        self::assertCount(2, $this->nodes('v2', Kind::Product), 'the diffuser is gone');
    }

    public function testWhatWooCommerceCannotHoldOfAProduct(): void
    {
        $candle = $this->nodes('v1', Kind::Product)[0];
        $options = [['type' => 'RADIO', 'name' => 'Wick', 'choices' => [['text' => 'Cotton', 'priceModifier' => 0]]], ['type' => 'TEXTFIELD', 'name' => 'Engraving']];

        $mapped = (new ProductMapper(false))->map(new Node([
            ...$candle->raw(),
            'weight_unit' => 'CARAT',
            'options' => $options,
            'wholesalePrices' => [['quantity' => 6, 'price' => 25]],
            'isShippingRequired' => false,
            'tax' => ['taxable' => false],
        ], $candle->id()));

        self::assertSame([
            '"Cedar candle" is weighed in CARAT, which WooCommerce has no unit for; its weight is meta.',
            '"Cedar candle" has options without variations (Wick); WooCommerce has no product options without an add-on plugin, so they are meta.',
            '"Cedar candle" has wholesale price tiers; WooCommerce has no tiered prices without a plugin, so they are meta.',
        ], $mapped->warnings);
        $variant = Values::of($mapped)->records('variants')[0];
        self::assertNull($variant->record('weight'));
        self::assertTrue($variant->bool('virtual'), 'needs no shipping');
        self::assertFalse($variant->bool('taxable'));

        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('gift card');
        (new ProductMapper(false))->map(new Node([...$candle->raw(), 'isGiftCard' => true], $candle->id()));
    }

    public function testCustomers(): void
    {
        [$rowan, $jun] = $this->mapAll('v1', Kind::Customer, new CustomerMapper());

        self::assertSame('rowan.pike@example.com', $rowan->nullableString('email'), 'lower-cased');
        self::assertSame('ecwid-9401', $rowan->string('username'));
        self::assertSame(['Rowan', 'Pike'], [$rowan->nullableString('first_name'), $rowan->nullableString('last_name')]);
        self::assertSame('+1 403-555-0190', $rowan->nullableString('phone'));
        self::assertNull($rowan->nullableString('locale'), 'a bare language code is no WordPress locale');
        self::assertSame('Prefers unscented gift wrap.', $rowan->nullableString('note'));
        self::assertSame('2026-09-01T15:00:00Z', $rowan->nullableString('created_at'));
        self::assertSame([
            'first_name' => 'Rowan', 'last_name' => 'Pike', 'address_1' => '14 Spray Avenue', 'address_2' => 'Apt 2', 'city' => 'Banff',
            'state' => 'AB', 'postcode' => 'T1L 1A1', 'country' => 'CA', 'phone' => '+1 403-555-0190',
        ], $rowan->record('billing')?->all(), "the street's two lines");
        self::assertSame('Rowan Pike', $rowan->record('shipping')?->string('first_name'), 'a saved address keeps the name whole');
        self::assertSame('General', $rowan->record('customer')?->string('customerGroupName'));
        self::assertSame('+1 587-555-0123', $jun->nullableString('phone'), 'the default phone contact');
        self::assertNull($jun->record('billing'), 'no address');
        self::assertNull($jun->record('shipping'));
        self::assertSame('fr', $jun->record('customer')?->string('lang'));
    }

    public function testDiscounts(): void
    {
        $nodes = $this->nodes('v1', Kind::Discount);
        $mapper = new DiscountMapper();
        $welcome = Values::of($mapper->map($nodes[0]) ?? self::fail('the welcome coupon maps'));
        $melts = Values::of($mapper->map($nodes[1]) ?? self::fail('the melts coupon maps'));
        $shipping = Values::of($mapper->map($nodes[2]) ?? self::fail('the free-shipping coupon maps'));

        self::assertSame('Welcome $5', $welcome->string('title'));
        self::assertSame(['ECW-WELCOME5'], $welcome->strings('codes'));
        self::assertSame('fixed_cart', $welcome->string('discount_type'), 'an amount off the whole order');
        self::assertSame('5', $welcome->string('amount'));
        self::assertSame('30', $welcome->nullableString('minimum_subtotal'));
        self::assertNull($welcome->nullableInt('usage_limit'));
        self::assertFalse($welcome->bool('once_per_customer'));
        self::assertSame('2026-09-01T00:00:00Z', $welcome->string('starts_at'));
        self::assertSame('2026-12-31T23:59:59Z', $welcome->nullableString('expires_at'));
        self::assertFalse($welcome->bool('free_shipping'));
        self::assertSame('percent', $melts->string('discount_type'));
        self::assertSame('15', $melts->string('amount'));
        self::assertSame(['gid://ecwid/Product/9202'], $melts->strings('product_gids'));
        self::assertTrue($melts->bool('once_per_customer'));
        self::assertNull($melts->nullableString('expires_at'));
        self::assertTrue($shipping->bool('free_shipping'));
        self::assertSame(['fixed_cart', '0'], [$shipping->string('discount_type'), $shipping->string('amount')]);
        self::assertSame(1, $shipping->nullableInt('usage_limit'), 'a single use');
        self::assertNull($mapper->map($nodes[3]), 'a paused coupon maps to nothing, so its coupon is retired');
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('NEW_CUSTOMER_ONLY');
        $mapper->map($nodes[4]);
    }

    public function testCouponsWooCommerceCannotApplyAreUnsupported(): void
    {
        $coupon = $this->nodes('v1', Kind::Discount)[0];
        $cases = [
            'amount off some products' => ['catalogLimit' => ['products' => [9201], 'categories' => []]],
            'front page category' => ['discountType' => 'PERCENT', 'catalogLimit' => ['products' => [], 'categories' => [0]]],
            'shipping methods' => ['shippingLimit' => ['shippingMethods' => ['8817-1693924000']]],
            'repeat customers' => ['applicationLimit' => 'REPEAT_CUSTOMER_ONLY'],
            'unknown type' => ['discountType' => 'BUY_X_GET_Y'],
        ];
        $unsupported = [];
        foreach ($cases as $case => $change) {
            try {
                (new DiscountMapper())->map(new Node([...$coupon->raw(), ...$change], $coupon->id()));
            } catch (Unsupported) {
                $unsupported[] = $case;
            }
        }

        self::assertSame(array_keys($cases), $unsupported);
        $both = Values::of((new DiscountMapper())->map(new Node([...$coupon->raw(), 'discountType' => 'PERCENT_AND_SHIPPING', 'catalogLimit' => ['products' => [], 'categories' => [9102]]], $coupon->id())) ?? self::fail('maps'));
        self::assertSame(['percent', true], [$both->string('discount_type'), $both->bool('free_shipping')]);
        self::assertSame(['gid://ecwid/Category/9102'], $both->strings('collection_gids'));
    }

    public function testOrders(): void
    {
        [$shipped, $pickup] = $this->mapAll('v1', Kind::Order, new OrderMapper());
        $products = $this->mapAll('v1', Kind::Product, new ProductMapper(false));

        self::assertSame('CW-1001', $shipped->string('name'), 'the id with the store prefix');
        self::assertSame('2026-09-02T16:00:00Z', $shipped->string('created_at'));
        self::assertSame('CAD', $shipped->string('currency'), "the store's currency");
        self::assertFalse($shipped->bool('prices_include_tax'));
        self::assertSame('gid://ecwid/Customer/9401', $shipped->nullableString('customer_gid'));
        self::assertSame('rowan.pike@example.com', $shipped->nullableString('email'));
        self::assertSame('+1 403-555-0190', $shipped->nullableString('phone'));
        self::assertSame('completed', $shipped->string('status'), 'paid and shipped');
        self::assertSame('Pike', $shipped->record('billing')?->string('last_name'));
        self::assertSame('Rowan Pike', $shipped->record('shipping')?->string('first_name'), 'the shipping person has one name');
        self::assertSame(['ECW-WELCOME5'], $shipped->strings('coupons'));
        [$candleLine, $meltLine] = $shipped->records('line_items');
        self::assertSame('gid://ecwid/OrderItem/960101', $candleLine->string('gid'));
        self::assertSame('gid://ecwid/Product/9201', $candleLine->nullableString('product_gid'));
        self::assertSame($products[0]->records('variants')[0]->string('gid'), $candleLine->nullableString('variant_gid'), 'a product without variations: its own variant');
        self::assertSame($products[1]->records('variants')[0]->string('gid'), $meltLine->nullableString('variant_gid'), 'the variation the product mapped (combinationId)');
        self::assertSame('ECW-CANDLE-1', $candleLine->nullableString('sku'));
        self::assertSame(2, $candleLine->int('quantity'));
        self::assertSame('56', $candleLine->string('subtotal'), '2 × 28 before the coupon');
        self::assertSame('52', $candleLine->string('total'), 'after its coupon amount');
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'amount' => '2.6']], $candleLine->raw('taxes'), 'rate in percent → fraction');
        self::assertSame([], $candleLine->stringMap('attributes'));
        self::assertSame(['Scent' => 'Fir'], $meltLine->stringMap('attributes'), 'the options the customer chose');
        self::assertSame('13', $meltLine->string('total'));
        self::assertSame(
            [['title' => 'Canada Post Expedited', 'code' => '8817-1693924000', 'source' => 'ecwid', 'total' => '12', 'taxes' => [['title' => 'GST', 'rate' => '0.05', 'amount' => '0.6']]]],
            $shipped->raw('shipping_lines'),
        );
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'order_amount' => null]], $shipped->raw('tax_lines'));
        self::assertSame(['5', '12', '3.85', '80.85'], [$shipped->string('discount_total'), $shipped->string('shipping_total'), $shipped->string('tax_total'), $shipped->string('total')]);
        self::assertSame([], $shipped->raw('fees'));
        self::assertSame([], $shipped->raw('refunds'));
        self::assertSame('2026-09-02T16:00:00Z', $shipped->nullableString('processed_at'), 'paid (Ecwid keeps no payment time)');
        self::assertSame('2026-09-04T11:00:00Z', $shipped->nullableString('closed_at'), 'when its shipping label was bought');
        self::assertSame('Leave it with the neighbour.', $shipped->nullableString('customer_note'));
        self::assertSame('Credit card', $shipped->nullableString('payment_method_title'));
        self::assertSame('ch_ecw_3Pz81', $shipped->nullableString('transaction_id'));
        self::assertSame([
            ['gid' => 'gid://ecwid/Tracking/CW-1001-CP123456789CA', 'created_at' => '2026-09-02T16:00:00Z', 'author' => null, 'message' => 'Ecwid tracking number CP123456789CA'],
            ['gid' => 'gid://ecwid/Shipment/CW-1001-lbl-7731', 'created_at' => '2026-09-04T11:00:00Z', 'author' => null, 'message' => 'Ecwid shipping label Canada Post tracking CP123456789CA'],
            ['gid' => 'gid://ecwid/Memo/CW-1001', 'created_at' => '2026-09-02T16:00:00Z', 'author' => 'Staff', 'message' => 'Gift for her sister.'],
        ], $shipped->raw('notes'));
        $order = $shipped->record('order') ?? self::fail('the Ecwid order is kept as a record');
        self::assertSame(96001, $order->int('internalId'));
        self::assertSame('SHIPPED', $order->string('fulfillmentStatus'));

        self::assertSame('pending', $pickup->string('status'), 'awaiting payment');
        self::assertNull($pickup->nullableString('customer_gid'), 'a guest order');
        self::assertNull($pickup->nullableString('processed_at'), 'not paid');
        self::assertNull($pickup->nullableString('closed_at'));
        self::assertSame([['title' => 'Pickup at the studio', 'code' => 'pickup-1', 'source' => 'ecwid', 'total' => '0', 'taxes' => []]], $pickup->raw('shipping_lines'));
        self::assertSame('29.4', $pickup->string('total'));
    }

    public function testRefundsAndNewOrders(): void
    {
        [$refunded, , $new] = $this->mapAll('v2', Kind::Order, new OrderMapper());

        self::assertSame([[
            'gid' => 'gid://ecwid/Refund/CW-1001-1',
            'created_at' => '2026-09-10T15:00:00Z',
            'note' => 'Cracked jar',
            'amount' => '29.4',
            'lines' => [],
        ]], $refunded->raw('refunds'), 'identified by its place in the list');
        self::assertSame('completed', $refunded->string('status'), 'partly refunded and delivered');
        self::assertSame('processing', $new->string('status'), 'paid, awaiting processing');
        self::assertSame('gid://ecwid/Customer/9403', $new->nullableString('customer_gid'));
        self::assertSame('gid://ecwid/Variant/920202', $new->records('line_items')[0]->nullableString('variant_gid'));
        self::assertSame(['28', '23.8'], [$new->records('line_items')[0]->string('subtotal'), $new->records('line_items')[0]->string('total')]);
        self::assertSame(['ECW-MELTS15'], $new->strings('coupons'));
        self::assertSame('33.39', $new->string('total'));
    }

    public function testOrderStatusRules(): void
    {
        self::assertSame('pending', OrderMapper::status('AWAITING_PAYMENT', 'AWAITING_PROCESSING'));
        self::assertSame('failed', OrderMapper::status('INCOMPLETE', null), 'an unfinished online payment');
        self::assertSame('cancelled', OrderMapper::status('CANCELLED', 'SHIPPED'));
        self::assertSame('refunded', OrderMapper::status('REFUNDED', 'DELIVERED'));
        self::assertSame('processing', OrderMapper::status('PAID', 'AWAITING_PROCESSING'));
        self::assertSame('processing', OrderMapper::status('PAID', 'READY_FOR_PICKUP'));
        self::assertSame('processing', OrderMapper::status('PAID', 'OUT_FOR_DELIVERY'));
        self::assertSame('completed', OrderMapper::status('PAID', 'SHIPPED'));
        self::assertSame('completed', OrderMapper::status('PAID', 'DELIVERED'));
        self::assertSame('completed', OrderMapper::status('PARTIALLY_REFUNDED', 'RETURNED'));
        self::assertSame('cancelled', OrderMapper::status('PAID', 'WILL_NOT_DELIVER'));
        self::assertSame('on-hold', OrderMapper::status('CUSTOM_PAYMENT_STATUS_1', 'PROCESSING'), "a store's own payment status");
    }

    public function testGrossPricesFeesAndAnOrderTaxedOnlyInTotal(): void
    {
        $order = $this->nodes('v1', Kind::Order)[1];
        $raw = $order->raw();
        $items = $raw['items'] ?? null;
        $item = is_array($items) ? $items[0] ?? null : null;
        if (!is_array($item)) {
            self::fail('the pickup order has an item');
        }

        $gross = Values::of((new OrderMapper())->map(new Node([
            ...$raw,
            'pricesIncludeTax' => true,
            'items' => [[...$item, 'price' => 29.4, 'priceWithoutTax' => 28, 'couponAmount' => 2.1, 'taxes' => [['name' => 'GST', 'value' => 5, 'total' => 1.3, 'taxOnDiscountedSubtotal' => 1.3]]]],
        ], $order->id())));
        $line = $gross->records('line_items')[0];
        self::assertTrue($gross->bool('prices_include_tax'));
        self::assertSame('28', $line->string('subtotal'), 'without tax');
        self::assertSame('26', $line->string('total'), 'the discounted gross price less its tax');

        $fees = Values::of((new OrderMapper())->map(new Node([
            ...$raw,
            'items' => [[...$item, 'taxes' => []]],
            'handlingFee' => ['name' => 'Gift wrap', 'value' => 3, 'description' => 'Kraft paper'],
            'customSurcharges' => [['id' => 'eco', 'description' => 'Eco fee', 'total' => 1.05, 'totalWithoutTax' => 1, 'taxes' => []]],
        ], $order->id())));
        self::assertSame([
            ['name' => 'Gift wrap', 'total' => '3', 'taxes' => []],
            ['name' => 'Eco fee', 'total' => '1', 'taxes' => []],
        ], $fees->raw('fees'));
        self::assertSame([['title' => 'Tax', 'rate' => null, 'order_amount' => '1.4']], $fees->raw('tax_lines'), 'tax reported only as the total');
    }

    public function testRedirects(): void
    {
        [$gift, $holiday] = $this->desired('v1', Kind::UrlRedirect, new UrlRedirectMapper());

        self::assertSame(['/cedar-gift'], $gift->legacyPaths);
        self::assertSame('/products/cedar-candle', $gift->redirectTarget, "a target on the store's site is its path, which redirects to the mirrored product");
        self::assertSame(['/holiday-sale'], $holiday->legacyPaths, 'a source URL is its path');
        self::assertSame('https://gifts.example.com/cedarwick', $holiday->redirectTarget, 'a target elsewhere keeps its URL');
    }

    public function testReviews(): void
    {
        [$published, $moderated] = $this->mapAll('v1', Kind::Review, new ReviewMapper());

        self::assertSame('gid://ecwid/Product/9201', $published->nullableString('product_gid'));
        self::assertSame(5, $published->int('rating'));
        self::assertSame('Smells like the forest after rain.', $published->string('content'));
        self::assertSame('Rowan P.', $published->string('author'));
        self::assertSame('Rowan.Pike@example.com', $published->string('email'));
        self::assertSame('2026-09-05T12:00:00Z', $published->string('created_at'));
        self::assertTrue($published->bool('approved'));
        self::assertTrue($published->bool('verified'), 'tied to an order');
        self::assertSame('gid://ecwid/Customer/9401', $published->nullableString('customer_gid'));
        self::assertFalse($moderated->bool('approved'), 'waiting for moderation');
        self::assertFalse($moderated->bool('verified'));
        self::assertNull($moderated->nullableString('customer_gid'));
        self::assertCount(3, $this->mapAll('v2', Kind::Review, new ReviewMapper()), 'a new review');
    }

    public function testFieldsAreExact(): void
    {
        $node = new Node(['a' => 53.719, 'b' => 0.1, 'c' => 1e2, 'zero' => 0, 'when' => '2026-09-01 10:00:00 +0200', 'tax' => ['name' => 'HST', 'value' => 13, 'total' => 1.3]]);

        self::assertSame('53.719', Fields::decimalOrZero($node, 'a'), 'three decimals, exactly');
        self::assertSame('0.1', Fields::decimalOrZero($node, 'b'));
        self::assertSame('100', Fields::decimalOrZero($node, 'c'));
        self::assertSame('0', Fields::decimalOrZero($node, 'missing'));
        self::assertNull(Fields::setAmount($node, 'zero'), 'zero is unset');
        self::assertSame('2026-09-01T08:00:00Z', Fields::time($node, 'when'));
        self::assertSame('0.13', Fields::rate($node->requiredChild('tax')));
        self::assertSame('lbs', Fields::weightUnit('POUND'));
        self::assertSame('g', Fields::weightUnit('gram'));
        self::assertNull(Fields::weightUnit('CARAT'));
        self::assertSame(['/products/cedar-candle'], Fields::legacyPaths('https://cedarwick.example/products/Cedar-Candle/'));
        self::assertSame([], Fields::legacyPaths('https://cedarwick.example/#!/Cedar-candle/p/9201'), 'a hashbang link has no path of its own');
        self::assertSame([], Fields::legacyPaths(null));
        self::assertSame(['first_name' => 'Jun', 'country' => 'CA'], Fields::address(new Node(['name' => 'Jun', 'countryCode' => 'CA'])), 'a one-word name, unsplit');
        self::assertSame('gid://ecwid/Variant/42-base', Fields::variantId(42, null));
        self::assertSame('gid://ecwid/Variant/4201', Fields::variantId(42, 4201));
    }

    public function testTheIncrementalReadMapsTheSameAsTheFullRead(): void
    {
        $since = new DateTimeImmutable('2026-09-09T00:00:00Z');
        $page = $this->reader('v2')->page(Kind::Product, null, $since, '');
        $single = $this->reader('v2')->node(Kind::Product, 'gid://ecwid/Product/9202') ?? self::fail('the melts are read singly');

        self::assertEquals((new ProductMapper(false))->map($this->nodes('v2', Kind::Product)[1]), (new ProductMapper(false))->map($page->nodes[1]));
        self::assertEquals((new ProductMapper(false))->map($page->nodes[1]), (new ProductMapper(false))->map($single), 'a product read singly maps the same');
    }

    /** @return list<Values> */
    private function mapAll(string $state, Kind $kind, Mapper $mapper): array
    {
        return array_map(Values::of(...), $this->desired($state, $kind, $mapper));
    }

    /** @return list<Desired> */
    private function desired(string $state, Kind $kind, Mapper $mapper): array
    {
        return array_map(static fn(Node $node): Desired => $mapper->map($node) ?? self::fail('mapped to nothing'), $this->nodes($state, $kind));
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

    private function reader(string $state): EcwidReader
    {
        return EcwidReader::connect('https://app.ecwid.test', '7400100', 'secret_fixture-token', new ScriptedTransport(new FixtureResponder('ecwid', $state)), new RecordingSleeper());
    }
}
