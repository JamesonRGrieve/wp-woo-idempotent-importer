<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Tests\Unit;

use IdempotentShopifyImporter\Mapping\ArticleMapper;
use IdempotentShopifyImporter\Mapping\BlogMapper;
use IdempotentShopifyImporter\Mapping\CollectionMapper;
use IdempotentShopifyImporter\Mapping\CommentMapper;
use IdempotentShopifyImporter\Mapping\CustomerMapper;
use IdempotentShopifyImporter\Mapping\DeliveryProfileMapper;
use IdempotentShopifyImporter\Mapping\Desired;
use IdempotentShopifyImporter\Mapping\DiscountMapper;
use IdempotentShopifyImporter\Mapping\DraftOrderMapper;
use IdempotentShopifyImporter\Mapping\FileMapper;
use IdempotentShopifyImporter\Mapping\Mapper;
use IdempotentShopifyImporter\Mapping\MenuMapper;
use IdempotentShopifyImporter\Mapping\OrderMapper;
use IdempotentShopifyImporter\Mapping\PageMapper;
use IdempotentShopifyImporter\Mapping\ProductMapper;
use IdempotentShopifyImporter\Mapping\ShopMapper;
use IdempotentShopifyImporter\Mapping\Unsupported;
use IdempotentShopifyImporter\Mapping\UrlRedirectMapper;
use IdempotentShopifyImporter\Mapping\Values;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Shopify\Connection;
use IdempotentShopifyImporter\Shopify\GraphQlClient;
use IdempotentShopifyImporter\Shopify\QueryLibrary;
use IdempotentShopifyImporter\Shopify\Reader;
use IdempotentShopifyImporter\Shopify\ShopifyNode;
use IdempotentShopifyImporter\Shopify\SourceRegistry;
use IdempotentShopifyImporter\Tests\Support\FixtureResponder;
use IdempotentShopifyImporter\Tests\Support\RecordingSleeper;
use IdempotentShopifyImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

/** Every mapper, run over the v1 store exactly as the importer reads it. */
final class MapperTest extends TestCase
{
    public function testShop(): void
    {
        $shop = $this->values(new ShopMapper(), $this->reader()->shop());

        self::assertSame('CAD', $shop->string('currency'));
        self::assertSame('g', $shop->string('weight_unit'));
        self::assertSame('AB', $shop->record('address')?->string('state'));
        self::assertSame('REFUND_POLICY', $shop->records('policies')[0]->string('type'));
    }

    public function testFiles(): void
    {
        [$image, $pdf] = $this->mapAll(Kind::File, new FileMapper());

        self::assertSame('http://shopify-fake:8080/cdn/shop/files/storefront.png', Values::of($image)->string('url'));
        self::assertSame('GenericFile', Values::of($pdf)->string('type'));
    }

    public function testCollections(): void
    {
        [$bras, $sale] = $this->mapAll(Kind::Collection, new CollectionMapper());

        self::assertSame(['/collections/bras'], $bras->legacyPaths);
        self::assertSame('Bras | Fixture Boutique', Values::of($bras)->seo()['title']);
        $rules = Values::of($sale)->record('smart_rules') ?? self::fail('the sale collection has rules');
        self::assertSame(['column' => 'IS_PRICE_REDUCED', 'relation' => 'IS_SET', 'condition' => ''], $rules->records('rules')[0]->all());
        self::assertSame('custom.care', $rules->records('rules')[1]->string('metafield'));
        self::assertFalse($rules->bool('disjunctive'));
        self::assertNull(Values::of($bras)->record('smart_rules'));
        self::assertSame(['custom.banner' => ['type' => 'single_line_text_field', 'value' => 'Up to 50% off']], Values::of($sale)->metafields());
    }

    public function testProducts(): void
    {
        $nodes = $this->nodes(Kind::Product);
        $mapper = new ProductMapper(false);
        $scarf = Values::of($mapper->map($nodes[0]));
        $variant = $scarf->records('variants')[0];

        self::assertTrue($scarf->bool('simple'));
        self::assertSame('Atelier North', $scarf->nullableString('brand'));
        self::assertSame('gid://shopify/TaxonomyCategory/aa-2-26', $scarf->nullableString('category_id'));
        self::assertSame(['gid://shopify/Collection/202'], $scarf->strings('collections'));
        self::assertSame('80', $variant->string('regular_price'));
        self::assertSame('60', $variant->string('sale_price'));
        self::assertSame('22.5', $variant->string('cost'));
        self::assertSame(['value' => '120', 'unit' => 'GRAMS'], $variant->record('weight')?->all());
        self::assertSame([], $scarf->records('attributes'));

        $braDesired = $mapper->map($nodes[1]);
        $bra = Values::of($braDesired);
        self::assertFalse($bra->bool('simple'));
        self::assertSame(['34B', '36C'], $bra->records('attributes')[0]->strings('values'));
        self::assertSame(['Size' => '36C', 'Color' => 'Beige'], $bra->records('variants')[1]->stringMap('options'));
        self::assertTrue($bra->records('variants')[0]->bool('backorders'));
        self::assertSame('Video', $bra->records('other_media')[0]->string('type'));
        self::assertCount(2, $bra->records('images'));
        self::assertSame(['"Everyday Bra" has 1 variant(s) without a SKU; WooCommerce Square needs one on each.'], $braDesired->warnings);

        $generated = Values::of((new ProductMapper(true))->map($nodes[1]))->records('variants')[1];
        self::assertSame('shopify-3022', $generated->string('sku'));

        $this->expectException(Unsupported::class);
        $mapper->map($nodes[2]);
    }

    public function testCustomers(): void
    {
        [$avery, $sam] = $this->mapAll(Kind::Customer, new CustomerMapper());
        $a = Values::of($avery);

        self::assertSame('avery.lin@example.com', $a->string('email'));
        self::assertSame('shopify-401', $a->string('username'));
        self::assertSame('T5J 1A1', $a->record('billing')?->string('postcode'));
        self::assertSame('Lin Design', $a->records('extra_addresses')[0]->string('company'));
        self::assertSame('SUBSCRIBED', $a->record('email_marketing')?->string('state'));
        self::assertSame([], Values::of($sam)->record('billing')?->all());
    }

    public function testDiscounts(): void
    {
        $nodes = $this->nodes(Kind::Discount);
        $mapper = new DiscountMapper();
        $fall = Values::of($mapper->map($nodes[0]));

        self::assertSame(['FALL15', 'AUTUMN15'], $fall->strings('codes'));
        self::assertSame('percent', $fall->string('discount_type'));
        self::assertSame('15', $fall->string('amount'));
        self::assertSame('50', $fall->string('minimum_subtotal'));
        self::assertSame(['gid://shopify/Collection/201'], $fall->strings('collection_gids'));
        self::assertTrue(Values::of($mapper->map($nodes[1]))->bool('free_shipping'));

        $this->expectException(Unsupported::class);
        $mapper->map($nodes[2]);
    }

    public function testOrders(): void
    {
        [$paid, $cancelled] = $this->mapAll(Kind::Order, new OrderMapper());
        $order = Values::of($paid);

        self::assertSame('completed', $order->string('status'));
        self::assertSame('#1001', $order->string('name'));
        self::assertSame('137.76', $order->string('total'));
        self::assertSame('61.2', $order->records('line_items')[1]->string('total'));
        self::assertSame('0.5', $order->records('shipping_lines')[0]->records('taxes')[0]->string('amount'));
        self::assertSame('gid://shopify/LineItem/8101', $order->records('refunds')[0]->records('lines')[0]->string('line_item_gid'));
        self::assertSame('Called customer about size.', $order->records('notes')[0]->string('message'));
        self::assertCount(1, $order->records('notes'));
        self::assertSame(['Gift message' => 'Happy birthday'], $order->stringMap('custom_attributes'));

        $void = Values::of($cancelled);
        self::assertSame('cancelled', $void->string('status'));
        self::assertNull($void->records('line_items')[0]->nullableString('product_gid'));
    }

    public function testOrderStatusRules(): void
    {
        self::assertSame('processing', OrderMapper::status(null, 'PAID', 'UNFULFILLED'));
        self::assertSame('completed', OrderMapper::status(null, 'PAID', 'FULFILLMENT_NOT_REQUIRED'));
        self::assertSame('on-hold', OrderMapper::status(null, 'AUTHORIZED', 'UNFULFILLED'));
        self::assertSame('refunded', OrderMapper::status('2026-01-01T00:00:00Z', 'REFUNDED', 'UNFULFILLED'));
        self::assertSame('cancelled', OrderMapper::status('2026-01-01T00:00:00Z', 'PAID', 'UNFULFILLED'));
        self::assertSame('pending', OrderMapper::status(null, null, 'UNFULFILLED'));
    }

    public function testDraftOrders(): void
    {
        $node = $this->nodes(Kind::DraftOrder)[0];
        $draft = Values::of((new DraftOrderMapper())->map($node) ?? self::fail('expected a desired state'));

        self::assertSame('pending', $draft->string('status'));
        self::assertSame('Custom fitting', $draft->string('customer_note'));

        $completed = new ShopifyNode(['status' => 'COMPLETED'] + $node->raw());
        self::assertNull((new DraftOrderMapper())->map($completed));
    }

    public function testContent(): void
    {
        [$about, $guide] = $this->mapAll(Kind::Page, new PageMapper());
        self::assertSame(['title' => 'About Fixture Boutique', 'description' => 'Our story.'], Values::of($about)->seo());
        self::assertSame('draft', Values::of($guide)->string('status'));
        self::assertSame('2026-07-02T10:00:00Z', Values::of($guide)->string('published_at'));

        self::assertSame(['/blogs/news'], $this->mapAll(Kind::Blog, new BlogMapper())[0]->legacyPaths);

        $article = $this->mapAll(Kind::Article, new ArticleMapper())[0];
        self::assertSame(['/blogs/news/fall-arrivals'], $article->legacyPaths);
        self::assertSame('Jamie', Values::of($article)->string('author'));

        [$kind, $spam] = $this->mapAll(Kind::Comment, new CommentMapper());
        self::assertSame('approve', Values::of($kind)->string('status'));
        self::assertSame('rae@example.com', Values::of($kind)->string('author_email'));
        self::assertSame('spam', Values::of($spam)->string('status'));
    }

    public function testNavigationAndRedirects(): void
    {
        $menu = Values::of($this->mapAll(Kind::Menu, new MenuMapper())[0]);
        self::assertSame('gid://shopify/Product/301', $menu->records('items')[1]->records('children')[0]->string('resource_gid'));

        $redirect = $this->mapAll(Kind::UrlRedirect, new UrlRedirectMapper())[0];
        self::assertSame(['/old-scarf'], $redirect->legacyPaths);
        self::assertSame('/products/silk-scarf', $redirect->redirectTarget);
    }

    public function testShipping(): void
    {
        $desired = $this->mapAll(Kind::DeliveryProfile, new DeliveryProfileMapper())[0];
        $zone = Values::of($desired)->records('zones')[0];

        self::assertSame(['CA:AB', 'CA:BC'], $zone->strings('locations'));
        self::assertSame('flat_rate', $zone->records('methods')[0]->string('type'));
        self::assertSame('100', $zone->records('methods')[1]->string('minimum_amount'));
        self::assertCount(2, $zone->records('methods'));
        self::assertStringContainsString('Heavy parcel', $desired->warnings[0]);
    }

    /** @return list<Desired> */
    private function mapAll(Kind $kind, Mapper $mapper): array
    {
        return array_map(static fn(ShopifyNode $node): Desired => $mapper->map($node) ?? self::fail('expected a desired state'), $this->nodes($kind));
    }

    /** @return list<ShopifyNode> */
    private function nodes(Kind $kind): array
    {
        return $this->reader()->page(SourceRegistry::for($kind), null, null)->nodes;
    }

    private function values(Mapper $mapper, ShopifyNode $node): Values
    {
        return Values::of($mapper->map($node) ?? self::fail('expected a desired state'));
    }

    private function reader(): Reader
    {
        $transport = new ScriptedTransport(new FixtureResponder('v1'));

        return new Reader(new GraphQlClient(Connection::forStore('fixture.myshopify.com', 't'), QueryLibrary::bundled(), $transport, new RecordingSleeper()));
    }
}
