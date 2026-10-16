<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Magento\MagentoReader;
use IdempotentImporter\Source\Magento\Mapping\CategoryMapper;
use IdempotentImporter\Source\Magento\Mapping\CustomerMapper;
use IdempotentImporter\Source\Magento\Mapping\DiscountMapper;
use IdempotentImporter\Source\Magento\Mapping\Fields;
use IdempotentImporter\Source\Magento\Mapping\OrderMapper;
use IdempotentImporter\Source\Magento\Mapping\PageMapper;
use IdempotentImporter\Source\Magento\Mapping\ProductMapper;
use IdempotentImporter\Source\Magento\Mapping\ShopMapper;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class MagentoMapperTest extends TestCase
{
    private const MEDIA = 'http://magento-fake:8080/media/';

    public function testShop(): void
    {
        $shop = Values::of((new ShopMapper())->map($this->reader('v1')->shop()));

        self::assertSame('Harbourside Goods', $shop->string('name'), "the store group's name, not the store view's");
        self::assertNull($shop->nullableString('email'), "Magento's REST API doesn't expose the store email");
        self::assertSame('CAD', $shop->string('currency'));
        self::assertSame('kg', $shop->nullableString('weight_unit'), 'kgs → kg');
        self::assertSame('America/Vancouver', $shop->nullableString('timezone'));
        self::assertNull($shop->raw('prices_include_tax'));
        self::assertNull($shop->record('address'));
        self::assertSame('en_CA', $shop->record('store')?->nullableString('locale'), 'kept as meta');
        self::assertSame([], $shop->records('policies'));
    }

    public function testWeightUnits(): void
    {
        self::assertSame('kg', Fields::weightUnit('KGS'));
        self::assertSame('lbs', Fields::weightUnit('lbs'));
        self::assertNull(Fields::weightUnit('stone'));
        self::assertNull(Fields::weightUnit(null));
    }

    public function testCategories(): void
    {
        $desired = $this->desired('v1', Kind::Collection, new CategoryMapper());
        [$galley, $outerwear, $rain] = array_map(Values::of(...), $desired);

        self::assertSame('Galley', $galley->string('name'));
        self::assertSame('galley', $galley->string('slug'), 'the url key');
        self::assertSame('<p>Cookware for the galley and the camp stove.</p>', $galley->nullableString('description_html'));
        self::assertSame(['url' => 'http://magento-fake:8080/media/catalog/category/galley.png', 'alt' => null], $galley->record('image')?->all(), 'a store path on the store URL');
        self::assertSame(['title' => 'Galley | Harbourside Goods', 'description' => 'Copper and enamel cookware.'], $galley->seo());
        self::assertNull($galley->nullableString('parent_gid'), 'directly under the store root');
        self::assertSame(['/galley.html'], $desired[0]->legacyPaths);
        self::assertNull($outerwear->record('image'));
        self::assertSame('gid://magento/Category/4', $rain->nullableString('parent_gid'));
        self::assertSame(['/outerwear/rain-jackets.html'], $desired[2]->legacyPaths, 'the url path');
        self::assertSame(['display_mode' => 'PRODUCTS', 'is_anchor' => '1'], $rain->raw('attributes'), 'the other attributes as meta, without the volatile child count');
    }

    public function testProducts(): void
    {
        $nodes = $this->nodes('v1', Kind::Product);
        $mapper = new ProductMapper(false);
        $kettleDesired = $mapper->map($nodes[0]) ?? self::fail('the kettle is mapped');
        $kettle = Values::of($kettleDesired);

        self::assertSame('Copper kettle', $kettle->string('title'));
        self::assertSame('copper-kettle', $kettle->string('slug'));
        self::assertSame('<p>A hand-hammered copper kettle.</p>', $kettle->string('description_html'));
        self::assertSame('publish', $kettle->string('status'));
        self::assertSame('visible', $kettle->string('catalog_visibility'), 'catalog, search → visible');
        self::assertSame('2026-09-01T09:00:00Z', $kettle->nullableString('created_at'), 'Magento times are UTC');
        self::assertSame(['gid://magento/Category/3'], $kettle->strings('collections'), 'the store root category is left out');
        self::assertSame([
            ['url' => self::MEDIA . 'catalog/product/c/o/copper-kettle.png', 'alt' => 'Copper kettle'],
            ['url' => self::MEDIA . 'catalog/product/c/o/copper-kettle-side.png', 'alt' => 'Side view'],
        ], $kettle->raw('images'), 'the base image first, then by position; a disabled image left out');
        self::assertSame(['/copper-kettle.html'], $kettleDesired->legacyPaths);
        self::assertSame(['title' => 'Copper kettle | Harbourside Goods', 'description' => 'A copper kettle for the stove.'], $kettle->seo());
        self::assertTrue($kettle->bool('simple'));
        self::assertSame(['tax_class_id' => '2', 'country_of_manufacture' => 'CA', 'options_container' => 'container2'], $kettle->raw('custom_attributes'));
        $base = $kettle->records('variants')[0];
        self::assertSame('gid://magento/Variant/11', $base->string('gid'));
        self::assertSame('MAG-KETTLE-1', $base->nullableString('sku'));
        self::assertSame('89.99', $base->string('regular_price'), 'a JSON number, exactly');
        self::assertSame('79.99', $base->nullableString('sale_price'), 'the special price');
        self::assertSame('41.5', $base->nullableString('cost'));
        self::assertSame(['value' => '1.4', 'unit' => 'kg'], $base->record('weight')?->all());
        self::assertTrue($base->bool('manage_stock'), "the configuration's default: stock managed");
        self::assertSame(7, $base->nullableInt('stock_quantity'));
        self::assertFalse($base->bool('backorders'));
        self::assertTrue($base->bool('taxable'));
        self::assertFalse($base->bool('virtual'));
        self::assertSame('CA', $base->nullableString('origin_country'));
        self::assertSame([], $kettleDesired->warnings);

        $jacket = Values::of($mapper->map($nodes[1]) ?? self::fail('the jacket is mapped'));
        self::assertFalse($jacket->bool('simple'));
        self::assertSame([['name' => 'Colour', 'position' => 1, 'values' => ['Red', 'Navy']]], $jacket->raw('attributes'), "the option labels of the child products' values");
        self::assertSame(['gid://magento/Category/4', 'gid://magento/Category/5'], $jacket->strings('collections'));
        [$red, $navy] = $jacket->records('variants');
        self::assertSame('gid://magento/Variant/121', $red->string('gid'));
        self::assertSame(['Colour' => 'Red'], $red->stringMap('options'));
        self::assertSame('Red', $red->string('title'));
        self::assertSame('MAG-JACKET-RED', $red->nullableString('sku'));
        self::assertSame('149', $red->string('regular_price'));
        self::assertSame(4, $red->nullableInt('stock_quantity'));
        self::assertSame(['value' => '0.9', 'unit' => 'kg'], $red->record('weight')?->all());
        self::assertNull($red->nullableString('image'));
        self::assertSame('159.5', $navy->string('regular_price'));
        self::assertSame(0, $navy->nullableInt('stock_quantity'));
        self::assertSame(self::MEDIA . 'catalog/product/s/t/storm-shell-navy.png', $navy->nullableString('image'));
        self::assertSame([121, 122], array_keys($jacket->record('variant_details')?->all() ?? []), "each child's details as meta");

        self::assertSame('private', Values::of($mapper->map($nodes[2]) ?? self::fail('the apron is mapped'))->string('status'), 'a disabled product is kept, private');

        $printDesired = $mapper->map($nodes[3]) ?? self::fail('the print is mapped');
        $print = Values::of($printDesired)->records('variants')[0];
        self::assertTrue($print->bool('virtual'), 'downloadable → virtual');
        self::assertFalse($print->bool('manage_stock'), 'its own stock setting: not managed');
        self::assertNull($print->nullableInt('stock_quantity'));
        self::assertNull($print->record('weight'));
        self::assertSame(['"Tide chart print" is downloadable; its files aren\'t mirrored (its links are meta).'], $printDesired->warnings);

        try {
            $mapper->map($nodes[4]);
            self::fail('a bundle has no exact WooCommerce equivalent');
        } catch (Unsupported $e) {
            self::assertStringContainsString('bundle', $e->getMessage());
        }
    }

    public function testProductsElsewhereAndChangedProducts(): void
    {
        $kettle = $this->nodes('v1', Kind::Product)[0];
        $raw = $kettle->raw();
        self::assertIsArray($raw['extension_attributes']);
        $raw['extension_attributes']['website_ids'] = [2];

        self::assertNull((new ProductMapper(false))->map(new Node($raw)), "a product not on the store's website maps to nothing");

        $v2 = $this->nodes('v2', Kind::Product);
        self::assertSame(['gid://magento/Product/11', 'gid://magento/Product/12', 'gid://magento/Product/14', 'gid://magento/Product/15'], array_map(static fn(Node $node): string => $node->id(), $v2), 'the apron was deleted');
        self::assertSame('94.99', Values::of((new ProductMapper(false))->map($v2[0]) ?? self::fail('mapped'))->records('variants')[0]->string('regular_price'));
    }

    public function testCustomers(): void
    {
        [$maeve, $oskar] = $this->mapAll('v1', Kind::Customer, new CustomerMapper());

        self::assertSame('maeve@example.com', $maeve->nullableString('email'), 'lower-cased');
        self::assertSame('magento-41', $maeve->string('username'));
        self::assertSame('Maeve', $maeve->nullableString('first_name'));
        self::assertSame('Doucet', $maeve->nullableString('last_name'));
        self::assertSame('+1 250-555-0141', $maeve->nullableString('phone'), "the billing address's phone");
        self::assertSame('2026-09-01T09:00:00Z', $maeve->nullableString('created_at'));
        $billing = [
            'first_name' => 'Maeve', 'last_name' => 'Doucet', 'address_1' => '12 Wharf Street', 'address_2' => 'Unit 3',
            'city' => 'Victoria', 'state' => 'BC', 'postcode' => 'V8W 1T3', 'country' => 'CA', 'phone' => '+1 250-555-0141',
        ];
        self::assertSame($billing, $maeve->record('billing')?->all(), 'the default billing address, the region by its code');
        self::assertSame($billing, $maeve->record('shipping')?->all());
        self::assertSame(1, $maeve->record('customer')?->int('group_id'));
        self::assertNull($oskar->record('billing'), 'no addresses');
        self::assertNull($oskar->nullableString('phone'));
        self::assertCount(3, $this->mapAll('v2', Kind::Customer, new CustomerMapper()), 'a new customer');
    }

    public function testDiscounts(): void
    {
        $mapper = new DiscountMapper();
        $rules = $this->nodes('v1', Kind::Discount);

        $welcome = Values::of($mapper->map($rules[0]) ?? self::fail('mapped'));
        self::assertSame(['MAG-WELCOME5'], $welcome->strings('codes'));
        self::assertSame('fixed_cart', $welcome->string('discount_type'));
        self::assertSame('5', $welcome->string('amount'));
        self::assertSame('50', $welcome->nullableString('minimum_subtotal'), 'a subtotal of at least 50');
        self::assertTrue($welcome->bool('once_per_customer'));
        self::assertNull($welcome->nullableInt('usage_limit'), 'unlimited');
        self::assertSame('2026-09-01T07:00:00Z', $welcome->string('starts_at'), "no from date: its coupon's creation");
        self::assertNull($welcome->nullableString('expires_at'));

        $fall = Values::of($mapper->map($rules[1]) ?? self::fail('mapped'));
        self::assertSame('percent', $fall->string('discount_type'));
        self::assertSame('15', $fall->string('amount'));
        self::assertSame(100, $fall->nullableInt('usage_limit'));
        self::assertFalse($fall->bool('once_per_customer'));
        self::assertSame('2026-09-01T07:00:00Z', $fall->string('starts_at'), 'the from date at midnight in Vancouver (PDT)');
        self::assertSame('2026-10-31T07:00:00Z', $fall->nullableString('expires_at'), 'the end of the to date in Vancouver');

        $shipping = Values::of($mapper->map($rules[2]) ?? self::fail('mapped'));
        self::assertTrue($shipping->bool('free_shipping'), 'free shipping for the whole cart');
        self::assertSame('0', $shipping->string('amount'));

        foreach ([3 => 'without a coupon', 4 => 'only to some items'] as $index => $why) {
            try {
                $mapper->map($rules[$index]);
                self::fail("a rule that applies {$why}");
            } catch (Unsupported $e) {
                self::assertStringContainsString($why, $e->getMessage());
            }
        }
        self::assertNull($mapper->map($rules[5]), 'an inactive rule retires its coupons');
    }

    public function testDiscountsWooCommerceCannotApply(): void
    {
        $raw = $this->nodes('v1', Kind::Discount)[0]->raw();
        $cases = [
            'customer groups' => ['customer_group_ids' => [1]],
            'times per customer' => ['uses_per_customer' => 3],
            'caps or steps' => ['discount_qty' => 2],
            'discounts shipping' => ['apply_to_shipping' => true],
            'ships some items free' => ['simple_free_shipping' => '1'],
            'buy_x_get_y' => ['simple_action' => 'buy_x_get_y'],
        ];
        foreach ($cases as $why => $change) {
            try {
                (new DiscountMapper())->map(new Node([...$raw, ...$change]));
                self::fail($why);
            } catch (Unsupported $e) {
                self::assertStringContainsString($why, $e->getMessage());
            }
        }
        self::assertNull((new DiscountMapper())->map(new Node([...$raw, 'website_ids' => [2]])), 'a rule for another website');
    }

    public function testOrders(): void
    {
        [$paid, $pending] = $this->mapAll('v1', Kind::Order, new OrderMapper());

        self::assertSame('#000000501', $paid->string('name'));
        self::assertSame('2026-09-03T14:00:00Z', $paid->string('created_at'));
        self::assertSame('CAD', $paid->string('currency'));
        self::assertSame('completed', $paid->string('status'));
        self::assertSame('gid://magento/Customer/41', $paid->nullableString('customer_gid'));
        self::assertSame('maeve@example.com', $paid->nullableString('email'));
        self::assertSame('258.82', $paid->string('total'));
        self::assertSame('12.33', $paid->string('tax_total'));
        self::assertSame('12', $paid->string('shipping_total'));
        self::assertSame('5', $paid->string('discount_total'), "Magento's negative discount as an amount");
        self::assertSame(['MAG-WELCOME5'], $paid->strings('coupons'));
        self::assertSame('Check / Money order', $paid->nullableString('payment_method_title'));
        self::assertSame('2026-09-03T14:00:00Z', $paid->nullableString('processed_at'));
        self::assertSame('2026-09-04T16:00:00Z', $paid->nullableString('closed_at'), 'its shipment');
        self::assertSame('Victoria', $paid->record('shipping')?->nullableString('city'));
        [$kettle, $jacket] = $paid->records('line_items');
        self::assertCount(2, $paid->records('line_items'), "the configurable product's child line is merged into its line");
        self::assertSame('gid://magento/Product/11', $kettle->nullableString('product_gid'));
        self::assertSame('gid://magento/Variant/11', $kettle->nullableString('variant_gid'));
        self::assertSame('79.99', $kettle->string('subtotal'));
        self::assertSame('78.32', $kettle->string('total'), 'after its share of the discount');
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'amount' => '3.92']], $kettle->raw('taxes'));
        self::assertSame('gid://magento/Product/12', $jacket->nullableString('product_gid'));
        self::assertSame('MAG-JACKET-NAVY', $jacket->nullableString('sku'));
        self::assertSame(1, $jacket->int('quantity'));
        self::assertSame('156.17', $jacket->string('total'));
        $variants = Values::of((new ProductMapper(false))->map($this->nodes('v1', Kind::Product)[1]) ?? self::fail('mapped'))->records('variants');
        self::assertSame($variants[1]->string('gid'), $jacket->nullableString('variant_gid'), 'the line names the variation the product mapper made for the navy child');
        self::assertSame([[
            'title' => 'Flat Rate - Fixed', 'code' => 'flatrate_flatrate', 'source' => 'magento', 'total' => '12',
            'taxes' => [['title' => 'GST', 'rate' => '0.05', 'amount' => '0.6']],
        ]], $paid->raw('shipping_lines'));
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'order_amount' => null]], $paid->raw('tax_lines'));
        self::assertSame(
            ['Magento shipment 000000801: Canada Post LM987654321CA', 'Left at the side door.'],
            array_map(static fn(Values $note): string => $note->string('message'), $paid->records('notes')),
        );
        self::assertSame('complete', $paid->record('order')?->nullableString('state'));

        self::assertSame('pending', $pending->string('status'), 'new → pending');
        self::assertNull($pending->nullableString('customer_gid'), 'a guest');
        self::assertNull($pending->nullableString('processed_at'), 'unpaid');
        self::assertNull($pending->nullableString('closed_at'));
        self::assertSame('178.48', $pending->string('total'));
        self::assertSame(2, $pending->records('line_items')[0]->int('quantity'), 'a JSON number quantity');
    }

    public function testRefundsAndNewOrders(): void
    {
        [$refunded, , $new] = $this->mapAll('v2', Kind::Order, new OrderMapper());

        self::assertSame('completed', $refunded->string('status'), 'a partly refunded order keeps its state');
        self::assertSame([[
            'gid' => 'gid://magento/CreditMemo/901', 'created_at' => '2026-09-08T11:00:00Z', 'note' => 'Dented lid: partial refund.',
            'amount' => '10', 'lines' => [],
        ]], $refunded->raw('refunds'), 'an adjustment refund returns no items');
        self::assertCount(3, $refunded->records('notes'), 'the refund comment too');
        self::assertSame('processing', $new->string('status'));
        self::assertSame('gid://magento/Customer/43', $new->nullableString('customer_gid'));
        self::assertSame('gid://magento/Variant/121', $new->records('line_items')[0]->nullableString('variant_gid'));
    }

    public function testOrderStates(): void
    {
        $expected = [
            'new' => 'pending', 'pending_payment' => 'pending', 'payment_review' => 'on-hold', 'holded' => 'on-hold',
            'processing' => 'processing', 'complete' => 'completed', 'closed' => 'refunded', 'canceled' => 'cancelled',
            'unknown' => 'pending',
        ];
        foreach ($expected as $state => $status) {
            self::assertSame($status, OrderMapper::status($state), $state);
        }
    }

    public function testPages(): void
    {
        $desired = $this->desired('v1', Kind::Page, new PageMapper());
        [$home, $about, $cookies] = array_map(Values::of(...), $desired);

        self::assertSame('Home page', $home->string('title'));
        self::assertSame('home', $home->string('slug'));
        self::assertSame([], $desired[0]->legacyPaths, 'the home page is the store root');
        self::assertSame('our-story', $about->string('slug'), "the identifier's last segment");
        self::assertSame("<p>Outfitting Victoria's harbour since 2015.</p>", $about->string('content_html'));
        self::assertSame('publish', $about->string('status'));
        self::assertSame('2026-09-01T08:00:00Z', $about->nullableString('published_at'));
        self::assertSame(['title' => 'Our story | Harbourside', 'description' => 'Our story at Harbourside Goods.'], $about->seo());
        self::assertSame(['/company/our-story'], $desired[1]->legacyPaths);
        self::assertSame('draft', $cookies->string('status'), 'an inactive page');
        self::assertCount(4, $this->mapAll('v2', Kind::Page, new PageMapper()), 'a new page');
    }

    public function testMoneyQuantitiesAndDatesAreExact(): void
    {
        $node = new Node(['a' => 19.99, 'b' => 0.1, 'c' => -5, 'd' => 2.0, 'e' => 1.5, 'z' => 0]);

        self::assertSame('19.99', Fields::decimalOrZero($node, 'a'));
        self::assertSame('0.1', Fields::decimalOrZero($node, 'b'));
        self::assertSame('5', Fields::magnitude(Fields::decimalOrZero($node, 'c')));
        self::assertSame('0', Fields::decimalOrZero($node, 'missing'));
        self::assertNull(Fields::setAmount($node, 'z'));
        self::assertSame(2, Fields::quantity($node, 'd'));
        self::assertSame('2026-03-08T08:00:00Z', Fields::dayStart('2026-03-08', 'America/Vancouver'));
        self::assertSame('2026-03-09T07:00:00Z', Fields::dayStart('2026-03-08', 'America/Vancouver', 1), 'across the DST change');
        self::assertNull(Fields::dayStart(null, 'UTC'));
        self::assertSame('/a/b.html', Fields::legacyPath('a/b'));
        self::assertSame(self::MEDIA . 'catalog/product/k/e/kettle.png', Fields::productMediaUrl(self::MEDIA, '/k/e/kettle.png'));
        $this->expectException(Unsupported::class);
        Fields::quantity($node, 'e');
    }

    /** @return list<Values> */
    private function mapAll(string $state, Kind $kind, Mapper $mapper): array
    {
        return array_map(Values::of(...), $this->desired($state, $kind, $mapper));
    }

    /** @return list<Desired> */
    private function desired(string $state, Kind $kind, Mapper $mapper): array
    {
        return array_map(
            static fn(Node $node): Desired => $mapper->map($node) ?? self::fail('mapped to nothing'),
            $this->nodes($state, $kind),
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

    private function reader(string $state): MagentoReader
    {
        return MagentoReader::connect('https://shop.magento.test', 'default', 'fixture-access-token', new ScriptedTransport(new FixtureResponder('magento', $state)), new RecordingSleeper());
    }
}
