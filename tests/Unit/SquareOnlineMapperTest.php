<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SquareOnline\Mapping\CategoryMapper;
use IdempotentImporter\Source\SquareOnline\Mapping\CustomerMapper;
use IdempotentImporter\Source\SquareOnline\Mapping\Fields;
use IdempotentImporter\Source\SquareOnline\Mapping\ItemMapper;
use IdempotentImporter\Source\SquareOnline\Mapping\OrderMapper;
use IdempotentImporter\Source\SquareOnline\Mapping\ShopMapper;
use IdempotentImporter\Source\SquareOnline\SquareOnlineReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class SquareOnlineMapperTest extends TestCase
{
    public function testShop(): void
    {
        $shop = Values::of((new ShopMapper())->map($this->reader('v1')->shop()));

        self::assertSame('Prairie Pottery', $shop->string('name'));
        self::assertSame('CAD', $shop->string('currency'));
        self::assertSame('hello@prairiepottery.example', $shop->nullableString('email'), "the main location's business email");
        self::assertSame('America/Edmonton', $shop->nullableString('timezone'));
        self::assertNull($shop->nullableString('weight_unit'), 'Square has no store weight unit, so WooCommerce keeps its own');
        self::assertSame(
            ['address_1' => '10 Jasper Ave', 'address_2' => 'Unit 4', 'city' => 'Edmonton', 'state' => 'AB', 'postcode' => 'T5J 0N3', 'country' => 'CA'],
            $shop->record('address')?->all(),
        );
        [$policy] = $shop->records('policies');
        self::assertSame('gid://square_online/ReturnPolicy/LOCMAIN1', $policy->string('gid'));
        self::assertSame("<p>Returns are accepted within 30 days.</p>\n<p>Items must be unused.</p>", $policy->string('content_html'));
        self::assertSame('prairiepottery.square.site', $shop->records('sites')[0]->nullableString('domain'), 'sites are mirrored as meta');
    }

    public function testCategories(): void
    {
        [$mugs, $linen] = $this->mapAll('v1', Kind::Collection, new CategoryMapper());

        self::assertSame('Mugs', $mugs->string('name'));
        self::assertSame('mugs', $mugs->string('slug'), 'the Square Online permalink');
        self::assertSame(['url' => 'http://square_online-fake:8080/img/img_cat_mugs.png', 'alt' => 'A row of mugs'], $mugs->record('image')?->all());
        self::assertSame(['title' => 'Mugs | Prairie Pottery', 'description' => null], $mugs->seo());
        self::assertSame('kitchen-linen', $linen->string('slug'));
        self::assertNull($linen->record('image'));
        self::assertSame('REGULAR_CATEGORY', $linen->nullableString('category_type'));
    }

    public function testItems(): void
    {
        $nodes = $this->nodes('v1', Kind::Product);
        $mapper = new ItemMapper(false);
        $mugDesired = $mapper->map($nodes[0]) ?? self::fail('the mug maps');
        $mug = Values::of($mugDesired);
        $apron = Values::of($mapper->map($nodes[1]) ?? self::fail('the apron maps'));
        $sample = Values::of($mapper->map($nodes[2]) ?? self::fail('the sample maps'));

        self::assertSame('Ocean mug', $mug->string('title'));
        self::assertSame('ocean-mug', $mug->string('slug'));
        self::assertSame('<p>Wheel-thrown stoneware.</p><p>Dishwasher safe.</p>', $mug->string('description_html'), 'Square keeps the description as HTML');
        self::assertSame('publish', $mug->string('status'));
        self::assertNull($mug->nullableString('created_at'), 'Square has no creation time for a catalog object');
        self::assertSame(['gid://square_online/Category/CAT_MUGS'], $mug->strings('collections'));
        self::assertSame(
            [['url' => 'http://square_online-fake:8080/img/img_mug_1.png', 'alt' => 'Ocean mug from above'], ['url' => 'http://square_online-fake:8080/img/img_mug_2.png', 'alt' => null]],
            $mug->raw('images'),
        );
        self::assertSame(['/product/ocean-mug/item_mug'], $mugDesired->legacyPaths, 'the old Square Online product URL redirects (paths are matched normalised)');
        self::assertTrue($mug->bool('simple'));
        $variant = $mug->records('variants')[0];
        self::assertSame('gid://square_online/ItemVariation/VAR_MUG', $variant->string('gid'));
        self::assertSame('PP-MUG-OCEAN', $variant->nullableString('sku'));
        self::assertSame('0629104150021', $variant->nullableString('gtin'));
        self::assertSame('32', $variant->string('regular_price'));
        self::assertTrue($variant->bool('manage_stock'));
        self::assertSame(4, $variant->nullableInt('stock_quantity'));
        self::assertSame('REGULAR', $mug->nullableString('square_product_type'), 'not the "product_type" the writer turns into an attribute');

        self::assertFalse($apron->bool('simple'));
        self::assertSame([['name' => 'Colour', 'position' => 1, 'values' => ['Natural', 'Charcoal']]], $apron->raw('attributes'));
        [$natural, $charcoal] = $apron->records('variants');
        self::assertSame(['Colour' => 'Natural'], $natural->stringMap('options'));
        self::assertSame('45', $natural->string('regular_price'));
        self::assertSame(3, $natural->nullableInt('stock_quantity'));
        self::assertSame('48', $charcoal->string('regular_price'), "the main location's price override wins");
        self::assertSame(0, $charcoal->nullableInt('stock_quantity'), 'sold out at the main location counts as zero');
        self::assertSame('http://square_online-fake:8080/img/img_apron_char.png', $charcoal->nullableString('image'));

        self::assertSame('private', $sample->string('status'), 'an archived item is kept, hidden');
        self::assertFalse($sample->records('variants')[0]->bool('manage_stock'));
        self::assertNull($sample->records('variants')[0]->nullableInt('stock_quantity'));
        self::assertNull($sample->records('variants')[0]->nullableString('sku'));
    }

    public function testGiftCardsAreNotProducts(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('GIFT_CARD');
        (new ItemMapper(false))->map($this->nodes('v1', Kind::Product)[3]);
    }

    public function testAnItemWithoutASkuWarnsOrGetsAGeneratedOne(): void
    {
        $sample = $this->nodes('v1', Kind::Product)[2];

        self::assertSame(['"Glaze sample" has 1 variant(s) without a SKU; WooCommerce Square needs one on each.'], (new ItemMapper(false))->map($sample)?->warnings);
        self::assertSame('square-VAR_SAMPLE', Values::of((new ItemMapper(true))->map($sample) ?? self::fail('maps'))->records('variants')[0]->nullableString('sku'));
    }

    public function testPriceChangesAndDeletedItems(): void
    {
        $mapper = new ItemMapper(false);
        [$mug] = $this->mapAll('v2', Kind::Product, $mapper, 1);
        self::assertSame('34', $mug->records('variants')[0]->string('regular_price'));

        $changed = $this->reader('v2')->page(Kind::Product, null, new DateTimeImmutable('2026-09-05T00:00:00Z'), '')->nodes;
        self::assertSame('gid://square_online/Item/ITEM_SAMPLE', $changed[1]->id());
        self::assertNull($mapper->map($changed[1]), 'a deleted item maps to nothing, so its copy is retired, never re-created');
    }

    public function testCustomers(): void
    {
        [$ada, $bob] = $this->mapAll('v1', Kind::Customer, new CustomerMapper());

        self::assertSame('square-CUST_ADA', $ada->string('username'));
        self::assertSame('ada@example.com', $ada->nullableString('email'));
        self::assertSame('2026-09-01T09:00:00Z', $ada->nullableString('created_at'));
        self::assertSame('Prefers email', $ada->nullableString('note'));
        self::assertSame([
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'company' => 'Analytical Engines', 'address_1' => '1 Main St', 'city' => 'Edmonton',
            'state' => 'AB', 'postcode' => 'T5J 1A1', 'country' => 'CA', 'phone' => '+1 780-555-0111',
        ], $ada->record('billing')?->all());
        self::assertSame(['first_name' => 'Bob', 'last_name' => 'Smith'], $bob->record('shipping')?->all(), 'no address: just the name');
        self::assertSame('ONLINE_STORE', $ada->nullableString('creation_source'));
    }

    public function testOrders(): void
    {
        [$shipped, $unpaid] = $this->mapAll('v1', Kind::Order, new OrderMapper());
        $products = $this->mapAll('v1', Kind::Product, new ItemMapper(false), 3);

        self::assertSame('#ORDER_1', $shipped->string('name'));
        self::assertSame('CAD', $shipped->string('currency'));
        self::assertSame('ada@example.com', $shipped->nullableString('email'));
        self::assertSame('gid://square_online/Customer/CUST_ADA', $shipped->nullableString('customer_gid'));
        self::assertSame('completed', $shipped->string('status'));
        self::assertFalse($shipped->bool('prices_include_tax'));
        self::assertSame('T5J 1A1', $shipped->record('shipping')?->nullableString('postcode'));
        [$mugLine, $apronLine] = $shipped->records('line_items');
        self::assertSame('64', $mugLine->string('subtotal'), '2 × 32');
        self::assertSame('64', $mugLine->string('total'));
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'amount' => '3.2']], $mugLine->raw('taxes'));
        self::assertSame('gid://square_online/Item/ITEM_MUG', $mugLine->nullableString('product_gid'));
        self::assertSame($products[0]->records('variants')[0]->string('gid'), $mugLine->nullableString('variant_gid'), 'a line points at the variant the item mapped');
        self::assertSame($products[1]->records('variants')[1]->string('gid'), $apronLine->nullableString('variant_gid'));
        self::assertSame('48', $apronLine->string('subtotal'), 'before the 10% discount');
        self::assertSame('43.2', $apronLine->string('total'));
        self::assertSame(['Variation' => 'Charcoal'], $apronLine->stringMap('attributes'));
        self::assertSame([['title' => 'Shipping', 'code' => null, 'source' => 'square', 'total' => '10', 'taxes' => [['title' => 'GST', 'rate' => '0.05', 'amount' => '0.5']]]], $shipped->raw('shipping_lines'));
        self::assertSame('10', $shipped->string('shipping_total'));
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'order_amount' => null]], $shipped->raw('tax_lines'));
        self::assertSame('5.86', $shipped->string('tax_total'));
        self::assertSame('4.8', $shipped->string('discount_total'));
        self::assertSame('123.06', $shipped->string('total'));
        self::assertSame([], $shipped->raw('fees'));
        self::assertSame('2026-09-03T12:01:00Z', $shipped->nullableString('processed_at'));
        self::assertSame('2026-09-04T09:00:00Z', $shipped->nullableString('closed_at'));
        self::assertSame('Leave at the side door', $shipped->nullableString('customer_note'));
        self::assertSame('Square (CARD)', $shipped->nullableString('payment_method_title'));
        self::assertSame('PAY_1', $shipped->nullableString('transaction_id'));
        self::assertSame([[
            'gid' => 'gid://square_online/Fulfillment/FUL_1-COMPLETED',
            'created_at' => '2026-09-04T09:00:00Z',
            'author' => null,
            'message' => 'Square shipment completed: Canada Post CP123456789CA',
        ]], $shipped->raw('notes'));
        self::assertSame('Fall sale', $shipped->records('discounts')[0]->nullableString('name'), 'discounts are mirrored as meta');

        self::assertSame('pending', $unpaid->string('status'), 'open and unpaid');
        self::assertNull($unpaid->nullableString('processed_at'));
        self::assertSame([], $unpaid->raw('shipping_lines'), 'a pickup has no shipping');
        self::assertSame('Pay at pickup', $unpaid->nullableString('customer_note'));
        self::assertSame('Square pickup proposed', $unpaid->records('notes')[0]->string('message'));
    }

    public function testRefundsAndNewOrders(): void
    {
        [$refunded, , $new] = $this->mapAll('v2', Kind::Order, new OrderMapper());

        self::assertSame('completed', $refunded->string('status'), 'partially refunded');
        $refund = $refunded->records('refunds')[0];
        self::assertSame('gid://square_online/Refund/REF_1', $refund->string('gid'));
        self::assertSame('10', $refund->string('amount'));
        self::assertSame('Chipped handle', $refund->nullableString('note'));
        self::assertSame('2026-09-06T15:00:00Z', $refund->nullableString('created_at'));
        self::assertSame('processing', $new->string('status'), 'open and paid');
        self::assertSame('gid://square_online/Customer/CUST_GRACE', $new->nullableString('customer_gid'));
        self::assertSame('57.75', $new->string('total'));
    }

    public function testOrderStatusRules(): void
    {
        self::assertSame('pending', OrderMapper::status('OPEN', false, false));
        self::assertSame('processing', OrderMapper::status('OPEN', true, false));
        self::assertSame('completed', OrderMapper::status('COMPLETED', true, false));
        self::assertSame('refunded', OrderMapper::status('COMPLETED', true, true));
        self::assertSame('cancelled', OrderMapper::status('CANCELED', false, true));
        self::assertSame('pending', OrderMapper::status('SOMETHING_NEW', true, false));
    }

    public function testReturnOrdersAreUnsupported(): void
    {
        $order = $this->nodes('v1', Kind::Order)[0];

        $this->expectException(Unsupported::class);
        (new OrderMapper())->map(new Node([...$order->raw(), 'returns' => [['uid' => 'RET_1', 'source_order_id' => 'ORDER_0']]]));
    }

    public function testMixedInclusiveAndAdditiveTaxesAreUnsupported(): void
    {
        $order = $this->nodes('v1', Kind::Order)[0];
        $taxes = $order->raw()['taxes'] ?? [];
        $inclusive = ['uid' => 'TAX_VAT', 'name' => 'VAT', 'type' => 'INCLUSIVE', 'percentage' => '20'];

        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('mixes inclusive and additive taxes');
        (new OrderMapper())->map(new Node([...$order->raw(), 'taxes' => [...(is_array($taxes) ? $taxes : []), $inclusive]]));
    }

    public function testMoneyIsExactInEveryCurrencysMinorUnit(): void
    {
        self::assertSame('0.05', Fields::money(new Node(['price' => ['amount' => 5, 'currency' => 'CAD']]), 'price'));
        self::assertSame('500', Fields::money(new Node(['price' => ['amount' => 500, 'currency' => 'JPY']]), 'price'), 'zero-decimal currency');
        self::assertSame('1.25', Fields::money(new Node(['price' => ['amount' => 1250, 'currency' => 'KWD']]), 'price'), 'three-decimal currency');
        self::assertNull(Fields::money(new Node(['price' => ['currency' => 'CAD']]), 'price'));
        $this->expectException(Unsupported::class);
        Fields::money(new Node(['price' => ['amount' => 100, 'currency' => 'BTC']]), 'price');
    }

    public function testFractionalQuantitiesAreUnsupported(): void
    {
        self::assertSame(3, Fields::wholeQuantity('3.000', 'stock'));
        $this->expectException(Unsupported::class);
        Fields::wholeQuantity('2.5', 'stock');
    }

    /** @return list<Values> */
    private function mapAll(string $state, Kind $kind, Mapper $mapper, ?int $limit = null): array
    {
        return array_map(
            static fn(Node $node): Values => Values::of($mapper->map($node) ?? self::fail('mapped to nothing')),
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

    private function reader(string $state): SquareOnlineReader
    {
        return SquareOnlineReader::connect('https://connect.squareup.test', 'fixture-access-token', new ScriptedTransport(new FixtureResponder('square_online', $state)), new RecordingSleeper());
    }
}
