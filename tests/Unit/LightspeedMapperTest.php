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
use IdempotentImporter\Source\Lightspeed\LightspeedReader;
use IdempotentImporter\Source\Lightspeed\Mapping\ArticleMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\BlogMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\CategoryMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\CustomerMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\DiscountMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\Fields;
use IdempotentImporter\Source\Lightspeed\Mapping\OrderMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\PageMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\ProductMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\ReviewMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\ShopMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\UrlRedirectMapper;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class LightspeedMapperTest extends TestCase
{
    private const MEDIA = 'http://lightspeed-fake:8080/files';

    public function testShop(): void
    {
        $shop = Values::of((new ShopMapper())->map($this->reader('v1')->shop()));

        self::assertSame('Harbour Lane Goods', $shop->string('name'), "the website's name");
        self::assertSame('hello@harbourlane.example', $shop->nullableString('email'));
        self::assertSame('CAD', $shop->string('currency'));
        self::assertTrue($shop->bool('prices_include_tax'), 'a retail shop shows prices with tax');
        self::assertNull($shop->nullableString('weight_unit'), 'each variant carries its own unit');
        self::assertNull($shop->nullableString('timezone'));
        self::assertSame(
            ['address_1' => 'Water Street', 'address_2' => 'Unit 4', 'city' => 'Halifax', 'postcode' => 'B3J 1S9', 'country' => 'CA'],
            $shop->record('address')?->all(),
            'the region is sent by name only, so the state is left alone',
        );
        $store = $shop->record('store') ?? self::fail('the shop details are kept');
        self::assertSame('Nova Scotia', $store->string('region'), 'kept as meta');
        self::assertSame('Harbour Lane Goods | Halifax', $store->record('website')?->string('title'));
        self::assertSame([], $shop->records('policies'));
    }

    public function testCategories(): void
    {
        $desired = $this->desired('v1', Kind::Collection, new CategoryMapper());
        [$tableware, $teaTowels] = array_map(Values::of(...), $desired);

        self::assertSame('Tableware', $tableware->string('name'));
        self::assertSame('tableware', $tableware->string('slug'));
        self::assertSame('<p>Everything for the table.</p>', $tableware->string('description_html'), 'the content');
        self::assertSame(['url' => self::MEDIA . '/ls-cat-tableware.png', 'alt' => null], $tableware->record('image')?->all());
        self::assertNull($tableware->nullableString('parent_gid'));
        self::assertSame(['/tableware'], $desired[0]->legacyPaths);
        self::assertSame('tea-towels', $teaTowels->string('slug'), 'the last part of its URL');
        self::assertSame('Tea towels short description', $teaTowels->string('description_html'), 'no content: the short description');
        self::assertSame('gid://lightspeed/Category/401', $teaTowels->nullableString('parent_gid'));
        self::assertNull($teaTowels->record('image'), 'an image sent as false');
        self::assertSame(['/tableware/tea-towels'], $desired[1]->legacyPaths, 'its URL is its whole path in the tree');
    }

    public function testProducts(): void
    {
        $desired = $this->desired('v1', Kind::Product, new ProductMapper(false));
        [$mug, $towel, $jug] = array_map(Values::of(...), $desired);

        self::assertSame('Stoneware mug', $mug->string('title'));
        self::assertSame('stoneware-mug', $mug->string('slug'));
        self::assertSame('<p>A hand-thrown stoneware mug.</p>', $mug->string('description_html'));
        self::assertSame('publish', $mug->string('status'));
        self::assertSame('2026-09-01T09:00:00Z', $mug->nullableString('created_at'));
        self::assertSame(['gid://lightspeed/Category/401'], $mug->strings('collections'));
        self::assertSame('Harbour Lane', $mug->nullableString('brand'));
        self::assertSame(
            [['url' => self::MEDIA . '/ls-mug.png', 'alt' => null], ['url' => self::MEDIA . '/ls-mug-side.png', 'alt' => null]],
            $mug->raw('images'),
            'by sort order',
        );
        self::assertSame(['/stoneware-mug.html'], $desired[0]->legacyPaths, 'the old storefront URL redirects');
        self::assertTrue($mug->bool('simple'), 'one variant with no options');
        $only = $mug->records('variants')[0];
        self::assertSame('gid://lightspeed/Variant/5011', $only->string('gid'));
        self::assertSame('Stoneware mug', $only->string('title'));
        self::assertSame('LS-MUG-1', $only->nullableString('sku'));
        self::assertSame('0881122330011', $only->nullableString('gtin'));
        self::assertSame('26.25', $only->string('regular_price'), 'with tax, as a retail shop shows it');
        self::assertNull($only->nullableString('sale_price'), 'no old price, no sale');
        self::assertSame('9.5', $only->nullableString('cost'));
        self::assertSame(['value' => '0.35', 'unit' => 'kg'], $only->record('weight')?->all());
        self::assertTrue($only->bool('manage_stock'));
        self::assertSame(14, $only->nullableInt('stock_quantity'));
        self::assertFalse($only->bool('backorders'), 'enabled tracking disallows backorders');
        self::assertSame('6912.00', $only->nullableString('hs_code'));
        self::assertSame('ls-mug-1', $mug->record('variant_details')?->record('5011')?->string('articleCode'));
        self::assertSame(78794, $mug->nullableInt('supplier_id'));

        self::assertFalse($towel->bool('simple'));
        self::assertSame([['name' => 'Colour', 'position' => 1, 'values' => ['Sand', 'Slate']]], $towel->raw('attributes'));
        self::assertSame(['gid://lightspeed/Category/402', 'gid://lightspeed/Category/401'], $towel->strings('collections'));
        [$sand, $slate] = $towel->records('variants');
        self::assertSame('gid://lightspeed/Variant/5021', $sand->string('gid'), 'variants in their sort order');
        self::assertSame(['Colour' => 'Sand'], $sand->stringMap('options'));
        self::assertSame('Sand', $sand->string('title'));
        self::assertSame('26.25', $sand->string('regular_price'), 'the old price above the price is the regular price');
        self::assertSame('21', $sand->nullableString('sale_price'), '… and the price the sale price');
        self::assertTrue($sand->bool('backorders'), 'indicator tracking allows backorders');
        self::assertSame(6, $sand->nullableInt('stock_quantity'));
        self::assertSame(['value' => '120', 'unit' => 'g'], $sand->record('weight')?->all(), 'in its own unit');
        self::assertSame('21', $slate->string('regular_price'));
        self::assertSame(self::MEDIA . '/ls-towel-slate.png', $slate->nullableString('image'));
        self::assertSame('0881122330028', $slate->nullableString('gtin'));

        self::assertSame('private', $jug->string('status'), 'a hidden product is kept, private');
        $jugVariant = $jug->records('variants')[0];
        self::assertFalse($jugVariant->bool('manage_stock'), 'tracking disabled');
        self::assertNull($jugVariant->nullableInt('stock_quantity'));
        self::assertNull($jugVariant->record('weight'), 'a zero weight is no weight');
        self::assertSame([], $jug->raw('images'));
        self::assertNull($jug->nullableString('brand'));
        self::assertSame(['"Prototype jug" has 1 variant(s) without a SKU; WooCommerce Square needs one on each.'], $desired[2]->warnings);
        self::assertSame('lightspeed-5031', Values::of((new ProductMapper(true))->map($this->nodes('v1', Kind::Product)[2]))->records('variants')[0]->nullableString('sku'));
    }

    public function testPriceChangesAndTheB2bPrices(): void
    {
        [$mug] = $this->mapAll('v2', Kind::Product, new ProductMapper(false));
        self::assertSame('27.3', $mug->records('variants')[0]->string('regular_price'));

        $towel = $this->nodes('v1', Kind::Product)[1];
        [$sand, $slate] = Values::of((new ProductMapper(false))->map(new Node([...$towel->raw(), 'shop_is_b2b' => true], $towel->id())))->records('variants');
        self::assertSame(['25', '20'], [$sand->string('regular_price'), $sand->nullableString('sale_price')], 'a B2B shop shows prices without tax');
        self::assertSame('20', $slate->string('regular_price'));
    }

    public function testVariantsWithoutMatrixOptionsAreNamedByTheirTitles(): void
    {
        $towel = $this->nodes('v1', Kind::Product)[1];
        $raw = $towel->raw();
        $variants = array_map(static fn(mixed $variant): array => [...(is_array($variant) ? $variant : []), 'options' => []], is_array($raw['variants'] ?? null) ? $raw['variants'] : []);

        $mapped = Values::of((new ProductMapper(false))->map(new Node([...$raw, 'variants' => $variants], $towel->id())));

        self::assertSame([['name' => 'Variant', 'position' => 1, 'values' => ['Colour Sand', 'Colour Slate']]], $mapped->raw('attributes'));
    }

    public function testCustomers(): void
    {
        [$marguerite, $owen] = $this->mapAll('v1', Kind::Customer, new CustomerMapper());

        self::assertSame('marguerite@example.com', $marguerite->nullableString('email'), 'lower-cased');
        self::assertSame('lightspeed-801', $marguerite->string('username'));
        self::assertSame(['Marguerite', 'Leblanc'], [$marguerite->nullableString('first_name'), $marguerite->nullableString('last_name')]);
        self::assertSame('Prefers email.', $marguerite->nullableString('note'));
        self::assertSame('en_GB', $marguerite->nullableString('locale'));
        self::assertSame('2026-09-01T10:00:00Z', $marguerite->nullableString('created_at'));
        self::assertSame([
            'first_name' => 'Marguerite', 'last_name' => 'Leblanc', 'address_1' => 'Spring Garden Road 12', 'city' => 'Halifax',
            'state' => 'Nova Scotia', 'postcode' => 'B3H 1X3', 'country' => 'CA', 'phone' => '+1 902-555-0141',
        ], $marguerite->record('billing')?->all(), 'street and number on one line; the region as sent');
        self::assertSame($marguerite->raw('billing'), $marguerite->raw('shipping'));
        self::assertNull($owen->record('billing'), 'no address');
        self::assertSame('registered', $marguerite->record('customer')?->string('type'));
    }

    public function testAddresses(): void
    {
        $node = new Node([
            'addressShippingName' => 'Front Desk', 'addressShippingStreet' => 'Keizersgracht', 'addressShippingNumber' => '12',
            'addressShippingExtension' => 'B', 'addressShippingCompany' => 'Canal Co', 'addressShippingCountry' => ['code' => 'nl'],
            'addressShippingRegionData' => ['name' => 'Noord-Holland', 'code' => 'NH'], 'addressShippingRegion' => 'Noord-Holland',
        ]);

        self::assertSame(
            ['first_name' => 'Front Desk', 'company' => 'Canal Co', 'address_1' => 'Keizersgracht 12B', 'state' => 'NH', 'country' => 'NL'],
            Fields::address($node, 'addressShipping', 'Anna', 'de Vries'),
            'a name other than the customer\'s is kept whole; the resolved region code',
        );
    }

    public function testDiscounts(): void
    {
        $nodes = $this->nodes('v1', Kind::Discount);
        $mapper = new DiscountMapper();
        $welcome = Values::of($mapper->map($nodes[0]) ?? self::fail('the welcome code maps'));
        $towels = Values::of($mapper->map($nodes[1]) ?? self::fail('the towels code maps'));
        $shipping = Values::of($mapper->map($nodes[2]) ?? self::fail('the free-shipping code maps'));

        self::assertSame(['LS-WELCOME5'], $welcome->strings('codes'));
        self::assertSame('fixed_cart', $welcome->string('discount_type'), 'an amount off the whole order');
        self::assertSame('5', $welcome->string('amount'));
        self::assertSame('30', $welcome->nullableString('minimum_subtotal'));
        self::assertSame(100, $welcome->nullableInt('usage_limit'));
        self::assertSame('2026-09-01T00:00:00Z', $welcome->string('starts_at'));
        self::assertSame('2026-12-31T23:59:59Z', $welcome->nullableString('expires_at'), 'the end date included');
        self::assertFalse($welcome->bool('free_shipping'));
        self::assertSame('percent', $towels->string('discount_type'));
        self::assertSame(['gid://lightspeed/Product/502'], $towels->strings('product_gids'));
        self::assertNull($towels->nullableInt('usage_limit'), '0 uses is unlimited');
        self::assertTrue($shipping->bool('free_shipping'));
        self::assertNull($mapper->map($nodes[3]), 'an inactive code maps to nothing, so its coupon is retired');
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('discounts shipping costs');
        $mapper->map($nodes[4]);
    }

    public function testDiscountsWooCommerceCannotApplyAreUnsupported(): void
    {
        $code = $this->nodes('v1', Kind::Discount)[0];
        $cases = [
            'amount off some products' => ['applyTo' => 'products', 'products' => [['id' => 502]]],
            'minimum after the discount' => ['minimum_after' => true],
            'after tax' => ['before_tax' => false],
        ];
        $unsupported = [];
        foreach ($cases as $case => $change) {
            try {
                (new DiscountMapper())->map(new Node([...$code->raw(), ...$change], $code->id()));
            } catch (Unsupported) {
                $unsupported[] = $case;
            }
        }

        self::assertSame(array_keys($cases), $unsupported);
        $categories = Values::of((new DiscountMapper())->map(new Node([...$code->raw(), 'type' => 'percentage', 'applyTo' => 'productscategories', 'products' => [['id' => 501]], 'categories' => [['id' => 402]]], $code->id())) ?? self::fail('maps'));
        self::assertSame(['gid://lightspeed/Product/501'], $categories->strings('product_gids'));
        self::assertSame(['gid://lightspeed/Category/402'], $categories->strings('collection_gids'));
    }

    public function testOrders(): void
    {
        [$shipped, $unpaid] = $this->mapAll('v1', Kind::Order, new OrderMapper());
        $products = $this->mapAll('v1', Kind::Product, new ProductMapper(false));

        self::assertSame('ORD00001', $shipped->string('name'));
        self::assertSame('2026-09-02T16:00:00Z', $shipped->string('created_at'));
        self::assertSame('CAD', $shipped->string('currency'), "the shop's currency");
        self::assertTrue($shipped->bool('prices_include_tax'));
        self::assertSame('gid://lightspeed/Customer/801', $shipped->nullableString('customer_gid'));
        self::assertSame('marguerite@example.com', $shipped->nullableString('email'));
        self::assertSame('completed', $shipped->string('status'));
        self::assertSame([
            'first_name' => 'Marguerite', 'last_name' => 'Leblanc', 'address_1' => 'Spring Garden Road 12', 'city' => 'Halifax',
            'state' => 'NS', 'postcode' => 'B3H 1X3', 'country' => 'CA', 'phone' => '+1 902-555-0141',
        ], $shipped->record('billing')?->all(), "the region's code");
        self::assertSame(['LS-WELCOME5'], $shipped->strings('coupons'));
        [$mugLine, $towelLine] = $shipped->records('line_items');
        self::assertSame('gid://lightspeed/OrderProduct/7011', $mugLine->string('gid'));
        self::assertSame('gid://lightspeed/Product/501', $mugLine->nullableString('product_gid'));
        self::assertSame($products[0]->records('variants')[0]->string('gid'), $mugLine->nullableString('variant_gid'), "a simple product's line points at its variant");
        self::assertSame($products[1]->records('variants')[1]->string('gid'), $towelLine->nullableString('variant_gid'), 'a line points at the variant the product mapped');
        self::assertSame('50', $mugLine->string('subtotal'), '2 × 25 before the coupon, without tax');
        self::assertSame('45', $mugLine->string('total'), 'after its share of the coupon');
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'amount' => '2.25']], $mugLine->raw('taxes'));
        self::assertSame([], $mugLine->stringMap('attributes'), 'the default variant says nothing');
        self::assertSame(['Variant' => 'Colour Slate'], $towelLine->stringMap('attributes'));
        self::assertSame(
            [['title' => 'Canada Post Expedited', 'code' => 'core|33265|55707', 'source' => 'lightspeed', 'total' => '10', 'taxes' => [['title' => 'GST', 'rate' => '0.05', 'amount' => '0.5']]]],
            $shipped->raw('shipping_lines'),
        );
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'order_amount' => null]], $shipped->raw('tax_lines'));
        self::assertSame('5', $shipped->string('discount_total'));
        self::assertSame('10', $shipped->string('shipping_total'));
        self::assertSame('3.75', $shipped->string('tax_total'), 'with tax − without tax');
        self::assertSame('78.75', $shipped->string('total'));
        self::assertSame([], $shipped->raw('fees'));
        self::assertSame('2026-09-02T16:00:00Z', $shipped->nullableString('processed_at'), 'paid');
        self::assertSame('2026-09-04T11:00:00Z', $shipped->nullableString('closed_at'), 'when its shipment shipped');
        self::assertSame('Please leave it at the back door.', $shipped->nullableString('customer_note'));
        self::assertSame('Credit card', $shipped->nullableString('payment_method_title'));
        self::assertSame([
            ['gid' => 'gid://lightspeed/Shipment/901', 'created_at' => '2026-09-04T11:00:00Z', 'author' => null, 'message' => 'Lightspeed shipment SHIP00001 (shipped) tracking CP987654321CA'],
            ['gid' => 'gid://lightspeed/Memo/701', 'created_at' => '2026-09-02T16:00:00Z', 'author' => 'Staff', 'message' => 'Wrap carefully.'],
        ], $shipped->raw('notes'));
        self::assertSame('paid', $shipped->record('order')?->string('paymentStatus'));

        self::assertSame('pending', $unpaid->string('status'), 'awaiting payment');
        self::assertNull($unpaid->nullableString('customer_gid'), 'a guest order');
        self::assertNull($unpaid->nullableString('processed_at'), 'not paid');
        self::assertNull($unpaid->nullableString('closed_at'));
        self::assertSame('Pickup in store', $unpaid->records('shipping_lines')[0]->string('title'));
        self::assertSame('21', $unpaid->string('total'));
    }

    public function testCreditNotesAndNewOrders(): void
    {
        [$refunded, , $new] = $this->mapAll('v2', Kind::Order, new OrderMapper());

        self::assertSame([[
            'gid' => 'gid://lightspeed/CreditNote/9102',
            'created_at' => '2026-09-10T15:00:00Z',
            'note' => 'Lightspeed credit note INV00004',
            'amount' => '26.25',
            'lines' => [['line_item_gid' => 'gid://lightspeed/OrderProduct/7011', 'quantity' => 1, 'subtotal' => '25']],
        ]], $refunded->raw('refunds'), 'the credited mug, as positive amounts');
        self::assertSame('processing', $new->string('status'), 'awaiting shipment');
        self::assertSame('gid://lightspeed/Customer/803', $new->nullableString('customer_gid'));
        self::assertSame('35.7', $new->string('total'));
    }

    public function testOrderStatusRules(): void
    {
        self::assertSame('on-hold', OrderMapper::status('on_hold'));
        self::assertSame('pending', OrderMapper::status('processing_awaiting_payment'));
        self::assertSame('processing', OrderMapper::status('processing_awaiting_shipment'));
        self::assertSame('processing', OrderMapper::status('processing_awaiting_pickup'));
        self::assertSame('processing', OrderMapper::status('processing_ready_for_pickup'));
        self::assertSame('completed', OrderMapper::status('completed'));
        self::assertSame('completed', OrderMapper::status('completed_shipped'));
        self::assertSame('completed', OrderMapper::status('completed_picked_up'));
        self::assertSame('cancelled', OrderMapper::status('cancelled'));
        self::assertSame('pending', OrderMapper::status('something_new'), 'an unknown status');
    }

    public function testPaymentCostsAndAnOrderTaxedOnlyInTotal(): void
    {
        $order = $this->nodes('v1', Kind::Order)[1];

        $mapped = Values::of((new OrderMapper())->map(new Node([
            ...$order->raw(),
            'taxRates' => [],
            'paymentTitle' => 'Cash on delivery',
            'paymentPriceExcl' => 2.5,
            'paymentTaxRates' => [['name' => 'GST', 'rate' => 0.05, 'amount' => 0.13]],
        ], $order->id())));

        self::assertSame([['name' => 'Cash on delivery', 'total' => '2.5', 'taxes' => [['title' => 'GST', 'rate' => '0.05', 'amount' => '0.13']]]], $mapped->raw('fees'));
        self::assertSame([['title' => 'Tax', 'rate' => null, 'order_amount' => '1']], $mapped->raw('tax_lines'));
    }

    public function testPages(): void
    {
        $desired = $this->desired('v1', Kind::Page, new PageMapper());
        [$about, $wholesale] = array_map(Values::of(...), $desired);

        self::assertSame('Our workshop', $about->string('title'));
        self::assertSame('our-workshop', $about->string('slug'));
        self::assertSame('<p>Made by hand in Halifax since 2019.</p>', $about->string('content_html'));
        self::assertSame('publish', $about->string('status'));
        self::assertSame('2026-08-03T09:00:00Z', $about->nullableString('published_at'));
        self::assertSame(['/service/our-workshop'], $desired[0]->legacyPaths);
        self::assertSame('draft', $wholesale->string('status'), 'a hidden page');
    }

    public function testBlogsAndArticles(): void
    {
        $blog = $this->desired('v1', Kind::Blog, new BlogMapper())[0];
        $articles = $this->desired('v2', Kind::Article, new ArticleMapper());
        $article = Values::of($articles[0]);

        self::assertSame('gid://lightspeed/Blog/1201', $blog->gid);
        self::assertSame(['Journal', 'journal'], [Values::of($blog)->string('name'), Values::of($blog)->string('slug')]);
        self::assertSame(['/blogs/journal'], $blog->legacyPaths);
        self::assertSame('Caring for linen', $article->string('title'));
        self::assertSame('caring-for-linen', $article->string('slug'));
        self::assertSame($blog->gid, $article->string('blog_gid'));
        self::assertSame('publish', $article->string('status'));
        self::assertSame('2026-09-03T09:00:00Z', $article->string('published_at'));
        self::assertSame('Marguerite Leblanc', $article->nullableString('author'));
        self::assertSame('<p>Caring for linen.</p>', $article->nullableString('excerpt_html'));
        self::assertSame(['url' => self::MEDIA . '/ls-linen-care.png', 'alt' => 'Caring for linen'], $article->record('image')?->all());
        self::assertSame(['/blogs/journal/caring-for-linen.html'], $articles[0]->legacyPaths);
        self::assertNull(Values::of($articles[1])->record('image'), 'a new article without an image');
    }

    public function testRedirects(): void
    {
        [$mug, $sale] = $this->desired('v1', Kind::UrlRedirect, new UrlRedirectMapper());

        self::assertSame(['/harbour-mug'], $mug->legacyPaths);
        self::assertSame(['/harbour-clearance'], $sale->legacyPaths);
        self::assertSame('/stoneware-mug.html', $mug->redirectTarget, "a target on the shop's domain is its path, which redirects to the mirrored product");
        self::assertSame('https://sale.example.com/harbour', $sale->redirectTarget, 'a target elsewhere keeps its URL');
    }

    public function testReviews(): void
    {
        [$visible, $hidden] = $this->mapAll('v1', Kind::Review, new ReviewMapper());

        self::assertSame('gid://lightspeed/Product/501', $visible->nullableString('product_gid'));
        self::assertSame(5, $visible->int('rating'));
        self::assertSame('Keeps tea hot.', $visible->string('content'));
        self::assertSame('Marguerite L.', $visible->string('author'));
        self::assertSame('', $visible->string('email'), 'Lightspeed sends no reviewer email');
        self::assertSame('2026-09-04T12:00:00Z', $visible->string('created_at'));
        self::assertTrue($visible->bool('approved'));
        self::assertFalse($visible->bool('verified'));
        self::assertSame('gid://lightspeed/Customer/801', $visible->nullableString('customer_gid'));
        self::assertFalse($hidden->bool('approved'), 'a hidden review is kept unapproved');
        self::assertCount(3, $this->mapAll('v2', Kind::Review, new ReviewMapper()), 'a new review');
    }

    public function testMoneyLinksAndTimesAreExact(): void
    {
        $node = new Node([
            'a' => 53.719, 'b' => 0.1, 'c' => 1e2, 'd' => '-26.2500', 'e' => 0, 'when' => '2026-09-01T10:00:00+02:00', 'never' => false,
            'link' => ['resource' => ['id' => 12, 'url' => 'brands/12', 'link' => 'x']], 'list' => ['resource' => ['id' => false, 'url' => 'x', 'link' => 'x']], 'none' => false,
        ]);

        self::assertSame('53.719', Fields::decimalOrZero($node, 'a'), 'three decimals, exactly');
        self::assertSame('0.1', Fields::decimalOrZero($node, 'b'));
        self::assertSame('100', Fields::decimalOrZero($node, 'c'));
        self::assertSame('26.25', Fields::magnitude(Fields::decimalOrZero($node, 'd')));
        self::assertNull(Fields::setAmount($node, 'e'), 'zero is unset');
        self::assertSame('2026-09-01T08:00:00Z', Fields::time($node, 'when'));
        self::assertNull(Fields::time($node, 'never'), 'a time sent as false');
        self::assertSame(12, Fields::linkedId($node, 'link'));
        self::assertNull(Fields::linkedId($node, 'list'), 'a link to a list names no object');
        self::assertNull(Fields::linkedId($node, 'none'));
        self::assertSame('lbs', Fields::weightUnit('lb'));
        self::assertNull(Fields::weightUnit('stone'));
        self::assertSame('/tableware/tea-towels', Fields::categoryPath('Tableware/Tea-Towels/'));
    }

    public function testTheIncrementalReadMapsTheSameAsTheFullRead(): void
    {
        $since = new DateTimeImmutable('2026-09-09T00:00:00Z');
        $reader = $this->reader('v2');
        $page = $reader->page(Kind::Product, 'variants:', $since, '');

        self::assertEquals((new ProductMapper(false))->map($this->nodes('v2', Kind::Product)[0]), (new ProductMapper(false))->map($page->nodes[0]));
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

    private function reader(string $state): LightspeedReader
    {
        return LightspeedReader::connect('https://api.lightspeed.test', 'en', 'fixture-api-key', 'fixture-api-secret', new ScriptedTransport(new FixtureResponder('lightspeed', $state)), new RecordingSleeper());
    }
}
