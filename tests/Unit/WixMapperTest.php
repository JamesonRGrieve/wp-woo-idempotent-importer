<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Wix\Mapping\ArticleMapper;
use IdempotentImporter\Source\Wix\Mapping\BlogMapper;
use IdempotentImporter\Source\Wix\Mapping\CategoryMapper;
use IdempotentImporter\Source\Wix\Mapping\ContactMapper;
use IdempotentImporter\Source\Wix\Mapping\CouponMapper;
use IdempotentImporter\Source\Wix\Mapping\Fields;
use IdempotentImporter\Source\Wix\Mapping\OrderMapper;
use IdempotentImporter\Source\Wix\Mapping\ProductMapper;
use IdempotentImporter\Source\Wix\Mapping\RedirectMapper;
use IdempotentImporter\Source\Wix\Mapping\ReviewMapper;
use IdempotentImporter\Source\Wix\Mapping\RichContent;
use IdempotentImporter\Source\Wix\Mapping\SiteMapper;
use IdempotentImporter\Source\Wix\WixReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class WixMapperTest extends TestCase
{
    private const MEDIA = 'http://wix-fake:8080/media';
    private const MUG = '1a7e5c10-0001-4c2e-9b41-6f0d2a1e0001';
    private const TOWEL = '1a7e5c10-0002-4c2e-9b41-6f0d2a1e0002';
    private const SAGE = '7c1d3e20-0001-4f8a-a3b2-1d9c4e5f0001';
    private const RUST = '7c1d3e20-0002-4f8a-a3b2-1d9c4e5f0002';
    private const DEFAULT_VARIANT = '00000000-0000-0000-0000-000000000000';
    private const MAYA = '2c4e6a80-0001-4b3d-9e1f-5a6b7c8d0001';

    public function testSite(): void
    {
        $site = Values::of((new SiteMapper())->map($this->reader('v1')->shop()));

        self::assertSame('Prairie Pottery Co.', $site->string('name'));
        self::assertSame('hello@prairiepottery.example', $site->nullableString('email'));
        self::assertSame('CAD', $site->string('currency'));
        self::assertSame('America/Regina', $site->nullableString('timezone'));
        self::assertNull($site->nullableString('weight_unit'));
        self::assertSame(
            ['address_1' => '214 Railway Street', 'city' => 'Moose Jaw', 'state' => 'SK', 'postcode' => 'S6H 1A1', 'country' => 'CA'],
            $site->record('address')?->all(),
        );
        self::assertSame('Prairie Pottery', $site->record('store')?->nullableString('siteDisplayName'));
        self::assertSame([], $site->records('policies'));
    }

    public function testCategories(): void
    {
        [$mugs, $linens] = $this->mapAll('v1', Kind::Collection, new CategoryMapper());

        self::assertSame('Mugs', $mugs->string('name'));
        self::assertSame('mugs', $mugs->string('slug'));
        self::assertSame("Everyday mugs.<br>\nDishwasher safe.", $mugs->string('description_html'), 'plain text, its line breaks kept');
        self::assertSame(['url' => self::MEDIA . '/cat-mugs.png', 'alt' => 'A row of mugs'], $mugs->record('image')?->all());
        self::assertSame(['title' => 'Mugs | Prairie Pottery', 'description' => 'Handmade stoneware mugs.'], $mugs->seo());
        self::assertNull($mugs->nullableString('parent_gid'));
        self::assertNull($linens->record('image'));
        self::assertSame('gid://wix/Category/3b9f2a40-0001-4d6c-8e1f-2a3b4c5d0001', $linens->nullableString('parent_gid'));
    }

    public function testV1CatalogCollections(): void
    {
        [$all, $planters] = $this->mapAll('catalog-v1', Kind::Collection, new CategoryMapper());

        self::assertSame('All Products', $all->string('name'));
        self::assertSame('planters', $planters->string('slug'));
        self::assertSame(['url' => self::MEDIA . '/planters.png', 'alt' => 'Planters'], $planters->record('image')?->all());
        self::assertSame('Pots for plants.', $planters->string('description_html'));
    }

    public function testV3Products(): void
    {
        $desired = $this->desired('v1', Kind::Product, new ProductMapper(false));
        [$mug, $towel, $bowl] = array_map(Values::of(...), $desired);

        self::assertSame('Speckled clay mug', $mug->string('title'));
        self::assertSame('speckled-clay-mug', $mug->string('slug'));
        self::assertSame('<p>Speckled clay mug, made in our Moose Jaw studio.</p>', $mug->string('description_html'));
        self::assertSame('publish', $mug->string('status'));
        self::assertSame('2026-08-03T15:00:00Z', $mug->nullableString('created_at'));
        self::assertSame(['gid://wix/Category/3b9f2a40-0001-4d6c-8e1f-2a3b4c5d0001'], $mug->strings('collections'));
        self::assertSame('Prairie Pottery', $mug->nullableString('brand'));
        self::assertSame(
            [['url' => self::MEDIA . '/mug-front.png', 'alt' => 'Speckled clay mug'], ['url' => self::MEDIA . '/mug-side.png', 'alt' => null]],
            $mug->raw('images'),
            'every media item in order, the main one first',
        );
        self::assertSame(['/product-page/speckled-clay-mug'], $desired[0]->legacyPaths, 'the old product page redirects');
        self::assertSame(['title' => 'Speckled clay mug | Prairie Pottery', 'description' => 'Speckled clay mug from Prairie Pottery.'], $mug->seo());
        self::assertTrue($mug->bool('simple'), 'no options: its one variant');
        $base = $mug->records('variants')[0];
        self::assertSame(Fields::variantId(self::MUG, self::DEFAULT_VARIANT), $base->string('gid'));
        self::assertSame('WIX-MUG-1', $base->nullableString('sku'));
        self::assertSame('0845511200019', $base->nullableString('gtin'), 'the barcode');
        self::assertSame('28', $base->string('regular_price'));
        self::assertNull($base->nullableString('sale_price'), 'no compare-at price, no sale');
        self::assertSame('9.5', $base->nullableString('cost'));
        self::assertSame(['value' => '0.45', 'unit' => 'kg'], $base->record('weight')?->all(), 'in the product\'s unit');
        self::assertTrue($base->bool('manage_stock'));
        self::assertSame(14, $base->nullableInt('stock_quantity'), "its inventory item's quantity");
        self::assertSame('Bestseller', $mug->record('product')?->record('ribbon')?->nullableString('name'), 'the ribbon is meta');

        self::assertFalse($towel->bool('simple'));
        self::assertSame([['name' => 'Colour', 'position' => 1, 'values' => ['Sage', 'Rust']]], $towel->raw('attributes'));
        [$sage, $rust] = $towel->records('variants');
        self::assertSame(Fields::variantId(self::TOWEL, self::SAGE), $sage->string('gid'));
        self::assertSame(['Colour' => 'Sage'], $sage->stringMap('options'));
        self::assertSame('Sage', $sage->string('title'));
        self::assertSame('22', $sage->string('regular_price'), 'the compare-at price is the regular price');
        self::assertSame('18', $sage->nullableString('sale_price'), 'and the selling price the sale price');
        self::assertSame(self::MEDIA . '/towel-sage.png', $sage->nullableString('image'));
        self::assertSame(6, $sage->nullableInt('stock_quantity'));
        self::assertSame('22', $rust->string('regular_price'));
        self::assertFalse($rust->bool('manage_stock'), 'tracked by status, not quantity');
        self::assertNull($rust->nullableInt('stock_quantity'));
        self::assertNull($rust->nullableString('gtin'));

        self::assertSame('private', $bowl->string('status'), 'a hidden product is kept, private');
    }

    public function testAPriceChange(): void
    {
        $mug = Values::of($this->desired('v2', Kind::Product, new ProductMapper(false))[0]);

        self::assertSame('30', $mug->records('variants')[0]->string('regular_price'));
    }

    public function testMissingSkusAreGeneratedOnlyWhenAsked(): void
    {
        $node = $this->nodes('v1', Kind::Product)[0];
        $raw = $node->raw();
        $variants = $raw['variants'];
        self::assertIsArray($variants);
        self::assertIsArray($variants[0]);
        unset($variants[0]['sku']);
        $withoutSku = new Node([...$raw, 'variants' => $variants], $node->id());

        $generated = Values::of((new ProductMapper(true))->map($withoutSku))->records('variants')[0];
        $missing = (new ProductMapper(false))->map($withoutSku);

        self::assertSame(ProductMapper::GENERATED_SKU_PREFIX . self::MUG . '-' . self::DEFAULT_VARIANT, $generated->nullableString('sku'));
        self::assertContains('"Speckled clay mug" has 1 variant(s) without a SKU; WooCommerce Square needs one on each.', $missing->warnings);
    }

    public function testV1CatalogProducts(): void
    {
        $desired = $this->desired('catalog-v1', Kind::Product, new ProductMapper(false));
        [$planter, $tumbler] = array_map(Values::of(...), $desired);

        self::assertTrue($planter->bool('simple'));
        self::assertSame('<p>A planter with a drainage hole.</p>', $planter->string('description_html'));
        self::assertSame(
            ['gid://wix/Collection/00000000-000000-000000-000000000001', 'gid://wix/Collection/c01e0000-1001-4a5b-8c9d-0e1f2a3b1001'],
            $planter->strings('collections'),
        );
        self::assertSame(['/product-page/stoneware-planter'], $desired[0]->legacyPaths);
        self::assertSame('Prairie Pottery', $planter->nullableString('brand'));
        $base = $planter->records('variants')[0];
        self::assertSame('34', $base->string('regular_price'));
        self::assertSame('29.5', $base->nullableString('sale_price'), 'a discounted price below the price is a sale');
        self::assertSame('11', $base->nullableString('cost'), "the product's cost");
        self::assertNull($base->record('weight'), 'V1 has no weight unit');
        self::assertSame(4, $base->nullableInt('stock_quantity'));
        self::assertContains('"Stoneware planter" has a weight, but Wix\'s V1 catalog doesn\'t say in which unit; its weight is meta.', $desired[0]->warnings);

        self::assertFalse($tumbler->bool('simple'));
        [$small, $large] = $tumbler->records('variants');
        self::assertSame(['Size' => 'Small'], $small->stringMap('options'));
        self::assertSame('16', $small->string('regular_price'));
        self::assertNull($small->nullableString('sale_price'));
        self::assertSame('5', $small->nullableString('cost'));
        self::assertSame('19', $large->string('regular_price'));
        self::assertFalse($large->bool('manage_stock'));
        self::assertContains('"Glazed tumbler" has 1 variant(s) Wix marks out of stock without tracking a quantity; WooCommerce keeps them in stock.', $desired[1]->warnings);
    }

    public function testContacts(): void
    {
        [$maya, $owen] = $this->mapAll('v1', Kind::Customer, new ContactMapper());

        self::assertSame('maya.thunderchild@example.com', $maya->nullableString('email'));
        self::assertSame('wix-' . self::MAYA, $maya->string('username'));
        self::assertSame('Maya', $maya->nullableString('first_name'));
        self::assertSame('+1 306 555 0177', $maya->nullableString('phone'));
        self::assertSame('en-CA', $maya->nullableString('locale'));
        self::assertSame('2026-08-10T12:00:00Z', $maya->nullableString('created_at'));
        self::assertSame([
            'first_name' => 'Maya', 'last_name' => 'Thunderchild', 'company' => 'Thunderchild Design', 'address_1' => '1200 Albert Street',
            'address_2' => 'Suite 4', 'city' => 'Regina', 'state' => 'SK', 'postcode' => 'S4R 2R4', 'country' => 'CA', 'phone' => '+1 306 555 0177',
        ], $maya->record('billing')?->all(), 'the address tagged billing, the contact\'s name, company and phone filling in');
        $shipping = $maya->record('shipping') ?? self::fail('a shipping address');
        self::assertSame('88 Coteau Street West', $shipping->nullableString('address_1'), 'number and street');
        self::assertSame('SK', $shipping->nullableString('state'), 'ISO 3166-2 CA-SK → SK');
        self::assertCount(2, $maya->records('addresses'));
        $member = $maya->record('member') ?? self::fail('member details');
        self::assertSame('ACTIVE', $member->nullableString('status'));
        self::assertNull($member->record('sessionInfo'), 'logins are not mirrored');
        self::assertNull($owen->record('billing'), 'no saved address');
    }

    public function testStates(): void
    {
        self::assertSame('AB', Fields::state('CA-AB', 'CA'));
        self::assertSame('NY', Fields::state('US-NY', 'us'));
        self::assertSame('AB', Fields::state('AB', 'CA'));
        self::assertNull(Fields::state(null, 'CA'));
    }

    public function testCoupons(): void
    {
        $mapper = new CouponMapper();
        $nodes = $this->nodes('v1', Kind::Discount);

        $welcome = Values::of($mapper->map($nodes[0]) ?? self::fail('mapped'));
        self::assertSame(['WIX-WELCOME10'], $welcome->strings('codes'));
        self::assertSame('percent', $welcome->string('discount_type'));
        self::assertSame('10', $welcome->string('amount'));
        self::assertSame('40', $welcome->nullableString('minimum_subtotal'));
        self::assertTrue($welcome->bool('once_per_customer'));
        self::assertSame('2026-08-01T12:00:00Z', $welcome->string('starts_at'), 'milliseconds since the epoch');
        self::assertSame('2026-12-31T23:59:59Z', $welcome->nullableString('expires_at'));
        self::assertSame([], $welcome->strings('product_gids'));

        $shipping = Values::of($mapper->map($nodes[1]) ?? self::fail('mapped'));
        self::assertTrue($shipping->bool('free_shipping'));
        self::assertSame('fixed_cart', $shipping->string('discount_type'));
        self::assertSame('0', $shipping->string('amount'));
        self::assertSame(100, $shipping->nullableInt('usage_limit'));

        $towel = Values::of($mapper->map($nodes[3]) ?? self::fail('mapped'));
        self::assertSame(['gid://wix/Product/' . self::TOWEL], $towel->strings('product_gids'));
        self::assertNull($mapper->map($nodes[4]), 'a deactivated coupon is retired');

        $planters = Values::of($mapper->map($this->nodes('catalog-v1', Kind::Discount)[0]) ?? self::fail('mapped'));
        self::assertSame(['gid://wix/Collection/c01e0000-1001-4a5b-8c9d-0e1f2a3b1001'], $planters->strings('collection_gids'), 'a V1 collection');

        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('amount off some products');
        $mapper->map($nodes[2]);
    }

    public function testOrders(): void
    {
        $desired = $this->desired('v1', Kind::Order, new OrderMapper());
        [$paid, $unpaid] = array_map(Values::of(...), $desired);

        self::assertSame('#10001', $paid->string('name'));
        self::assertSame('2026-09-02T17:30:00Z', $paid->string('created_at'));
        self::assertSame('CAD', $paid->string('currency'));
        self::assertFalse($paid->bool('prices_include_tax'));
        self::assertSame('gid://wix/Contact/' . self::MAYA, $paid->nullableString('customer_gid'));
        self::assertSame('maya.thunderchild@example.com', $paid->nullableString('email'));
        self::assertSame('SK', $paid->record('billing')?->nullableString('state'));
        self::assertSame('88 Coteau Street West', $paid->record('shipping')?->nullableString('address_1'));
        self::assertSame(['WIX-WELCOME10'], $paid->strings('coupons'));
        [$towelLine, $mugLine] = $paid->records('line_items');
        self::assertSame(Fields::variantId(self::TOWEL, self::SAGE), $towelLine->nullableString('variant_gid'), 'the variation the product mapper made');
        self::assertSame(Fields::variantId(self::MUG, self::DEFAULT_VARIANT), $mugLine->nullableString('variant_gid'));
        self::assertSame('gid://wix/Product/' . self::TOWEL, $towelLine->nullableString('product_gid'));
        self::assertSame('WIX-TOWEL-SAGE', $towelLine->nullableString('sku'));
        self::assertSame(2, $towelLine->int('quantity'));
        self::assertSame('36', $towelLine->string('subtotal'), 'before its share of the coupon');
        self::assertSame('32.4', $towelLine->string('total'));
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'amount' => '1.62']], $towelLine->raw('taxes'));
        self::assertSame(['Colour' => 'Sage'], $towelLine->stringMap('attributes'));
        $shipping = $paid->records('shipping_lines')[0];
        self::assertSame('Canada Post Regular Parcel', $shipping->string('title'));
        self::assertSame('9', $shipping->string('total'));
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'order_amount' => null]], $paid->raw('tax_lines'));
        self::assertSame('6.4', $paid->string('discount_total'));
        self::assertSame('9', $paid->string('shipping_total'));
        self::assertSame('3.33', $paid->string('tax_total'));
        self::assertSame('69.93', $paid->string('total'));
        self::assertSame('completed', $paid->string('status'), 'paid and fulfilled');
        self::assertSame('2026-09-02T17:31:00Z', $paid->nullableString('processed_at'));
        self::assertSame('2026-09-03T15:00:00Z', $paid->nullableString('closed_at'));
        self::assertSame('Please wrap the mug well.', $paid->nullableString('customer_note'));
        self::assertSame('Credit/Debit Cards', $paid->nullableString('payment_method_title'));
        self::assertSame('pi_wix_0001', $paid->nullableString('transaction_id'));
        self::assertStringStartsWith('Wix fulfillment: Canada Post 7023210039414604', $paid->records('notes')[0]->string('message'));
        self::assertSame([], $paid->records('refunds'));

        self::assertSame('on-hold', $unpaid->string('status'), 'an offline payment still to come');
        self::assertNull($unpaid->nullableString('processed_at'));
        self::assertSame([], $unpaid->records('shipping_lines'));
        self::assertSame([], $unpaid->raw('tax_lines'));
    }

    public function testRefundsAndANewOrder(): void
    {
        [$refunded, , $new] = array_map(Values::of(...), $this->desired('v2', Kind::Order, new OrderMapper()));

        self::assertSame('completed', $refunded->string('status'), 'partly refunded after fulfillment');
        $refunds = $refunded->records('refunds');
        self::assertCount(1, $refunds, 'a failed refund returned nothing');
        self::assertSame('17.01', $refunds[0]->string('amount'));
        self::assertSame('One towel arrived torn', $refunds[0]->nullableString('note'));
        self::assertSame(
            [['line_item_gid' => 'gid://wix/OrderLineItem/6a5b4c30-0001-4d2e-8f1a-9b0c1d2e0001', 'quantity' => 1, 'subtotal' => '17.01']],
            $refunds[0]->raw('lines'),
        );
        self::assertSame('processing', $new->string('status'));
        self::assertSame(Fields::variantId(self::TOWEL, self::RUST), $new->records('line_items')[0]->nullableString('variant_gid'));
    }

    public function testOrderStatuses(): void
    {
        self::assertSame('cancelled', OrderMapper::status('CANCELED', 'PAID', 'FULFILLED'));
        self::assertSame('failed', OrderMapper::status('REJECTED', 'DECLINED', null));
        self::assertSame('pending', OrderMapper::status('INITIALIZED', 'NOT_PAID', null));
        self::assertSame('refunded', OrderMapper::status('APPROVED', 'FULLY_REFUNDED', 'FULFILLED'));
        self::assertSame('failed', OrderMapper::status('APPROVED', 'DECLINED', 'NOT_FULFILLED'));
        self::assertSame('processing', OrderMapper::status('APPROVED', 'PAID', 'PARTIALLY_FULFILLED'));
        self::assertSame('on-hold', OrderMapper::status('APPROVED', 'PARTIALLY_PAID', 'NOT_FULFILLED'));
        self::assertSame('on-hold', OrderMapper::status('APPROVED', 'PENDING_MERCHANT', 'NOT_FULFILLED'));
    }

    public function testBlogAndPosts(): void
    {
        $blogs = $this->desired('v1', Kind::Blog, new BlogMapper());
        [$blog, $category] = array_map(Values::of(...), $blogs);
        self::assertSame('Blog', $blog->string('name'));
        self::assertSame(['/blog'], $blogs[0]->legacyPaths);
        self::assertSame('Studio notes', $category->string('name'));
        self::assertSame('studio-notes', $category->string('slug'));
        self::assertSame(['/blog/categories/studio-notes'], $blogs[1]->legacyPaths);

        $posts = $this->desired('v2', Kind::Article, new ArticleMapper());
        [$firing, $glaze] = array_map(Values::of(...), $posts);
        self::assertSame('Firing the anagama', $firing->string('title'));
        self::assertSame(
            '<h2>Three days of fire</h2><p>We stoke <strong>every five minutes</strong> — see <a href="https://www.prairiepottery.example/kiln">our kiln</a>.</p>'
            . '<ul><li><p>Ash glaze</p></li><li><p>Flashing</p></li></ul>'
            . '<figure><img src="' . self::MEDIA . '/kiln.png" alt="The kiln at night"></figure>',
            $firing->string('content_html'),
        );
        self::assertSame('Three days &amp; nights of stoking.', $firing->nullableString('excerpt_html'));
        self::assertSame(['woodfire', 'studio'], $firing->strings('tags'));
        self::assertSame('2026-08-15T14:00:00Z', $firing->nullableString('published_at'));
        self::assertSame('gid://wix/BlogCategory/4d3c2b10-0001-4e5f-8a9b-0c1d2e3f0001', $firing->string('blog_gid'), 'its first category');
        self::assertSame(['url' => self::MEDIA . '/kiln-cover.png', 'alt' => 'Kiln cover'], $firing->record('image')?->all());
        self::assertSame(['/post/firing-the-anagama'], $posts[0]->legacyPaths);
        self::assertSame('gid://wix/Blog/fixture-site', $glaze->string('blog_gid'), 'no category: the site blog');
    }

    public function testRichContentThatHasNoHtmlIsUnsupported(): void
    {
        self::assertSame('', RichContent::html(null));
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('VIDEO block');
        RichContent::html(new Node(['nodes' => [['type' => 'VIDEO', 'id' => 'v', 'nodes' => []]]]));
    }

    public function testRedirects(): void
    {
        $nodes = $this->nodes('v1', Kind::UrlRedirect);
        $mapper = new RedirectMapper();

        $mug = $mapper->map($nodes[0]);
        self::assertSame(['/old-mug'], $mug->legacyPaths);
        self::assertSame('/product-page/speckled-clay-mug', $mug->redirectTarget, 'resolves to wherever the product was mirrored');
        self::assertSame('https://sale.prairiepottery.example/', $mapper->map($nodes[1])->redirectTarget);
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('every path under it');
        $mapper->map($nodes[2]);
    }

    public function testReviews(): void
    {
        [$mug, $towel] = $this->mapAll('v1', Kind::Review, new ReviewMapper());

        self::assertSame('gid://wix/Product/' . self::MUG, $mug->string('product_gid'));
        self::assertSame(5, $mug->int('rating'));
        self::assertSame("<strong>Perfect morning mug</strong>\n\nHolds heat &amp; feels great.", $mug->string('content'));
        self::assertSame('Maya T.', $mug->string('author'));
        self::assertSame('', $mug->string('email'), 'Wix gives a contact, not an email');
        self::assertTrue($mug->bool('approved'));
        self::assertTrue($mug->bool('verified'));
        self::assertSame('Thank you, Maya!', $mug->nullableString('reply'));
        self::assertFalse($towel->bool('approved'), 'in moderation');
        self::assertSame('2026-09-05T12:00:00Z', $towel->string('created_at'));
    }

    public function testMapperVersionsStartAtOne(): void
    {
        foreach ([new SiteMapper(), new CategoryMapper(), new ProductMapper(false), new ContactMapper(), new CouponMapper(), new OrderMapper(), new BlogMapper(), new ArticleMapper(), new RedirectMapper(), new ReviewMapper()] as $mapper) {
            self::assertSame(1, $mapper->version(), $mapper::class);
        }
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

    private function reader(string $state): WixReader
    {
        return WixReader::connect('https://www.wixapis.test', 'fixture-api-key', 'fixture-site', new ScriptedTransport(new FixtureResponder('wix', $state)), new RecordingSleeper());
    }
}
