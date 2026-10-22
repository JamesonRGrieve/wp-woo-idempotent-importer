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
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\Webflow\Mapping\ArticleMapper;
use IdempotentImporter\Source\Webflow\Mapping\AssetMapper;
use IdempotentImporter\Source\Webflow\Mapping\BlogMapper;
use IdempotentImporter\Source\Webflow\Mapping\CategoryMapper;
use IdempotentImporter\Source\Webflow\Mapping\CollectionMapper;
use IdempotentImporter\Source\Webflow\Mapping\CustomerMapper;
use IdempotentImporter\Source\Webflow\Mapping\Fields;
use IdempotentImporter\Source\Webflow\Mapping\ItemMapper;
use IdempotentImporter\Source\Webflow\Mapping\OrderMapper;
use IdempotentImporter\Source\Webflow\Mapping\PageMapper;
use IdempotentImporter\Source\Webflow\Mapping\ProductMapper;
use IdempotentImporter\Source\Webflow\Mapping\RedirectMapper;
use IdempotentImporter\Source\Webflow\Mapping\SiteMapper;
use IdempotentImporter\Source\Webflow\WebflowReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class WebflowMapperTest extends TestCase
{
    private const MEDIA = 'http://webflow-fake:8080/images';

    public function testSite(): void
    {
        $shop = Values::of((new SiteMapper())->map($this->reader('v1')->shop()));

        self::assertSame('Fernhill Ceramics', $shop->string('name'));
        self::assertSame('CAD', $shop->nullableString('currency'));
        self::assertSame('America/Vancouver', $shop->nullableString('timezone'));
        self::assertNull($shop->raw('policies'), 'Webflow has no policy pages of its own');
        self::assertSame('fernhill-ceramics', $shop->record('store')?->string('shortName'));
    }

    public function testAssets(): void
    {
        [$studio] = array_map(Values::of(...), $this->desired('v1', Kind::File, new AssetMapper()));

        self::assertSame(self::MEDIA . '/wf-studio.png', $studio->string('download_url'));
        self::assertSame("The studio's kiln room", $studio->nullableString('alt'));
        self::assertSame('studio.png', $studio->record('asset')?->string('originalFileName'));
    }

    public function testCategories(): void
    {
        $desired = $this->desired('v1', Kind::Collection, new CategoryMapper());
        [$mugs, $bowls] = array_map(Values::of(...), $desired);

        self::assertSame('Mugs', $mugs->string('name'));
        self::assertSame('mugs', $mugs->string('slug'));
        self::assertSame('<p>Wheel-thrown mugs.</p>', $mugs->string('description_html'), 'the rich text field');
        self::assertSame(['url' => self::MEDIA . '/wf-cat-mugs.png', 'alt' => 'A row of mugs'], $mugs->record('image')?->all());
        self::assertSame(['/category/mugs'], $desired[0]->legacyPaths);
        self::assertNull($bowls->record('image'));
    }

    public function testProducts(): void
    {
        $desired = $this->desired('v1', Kind::Product, new ProductMapper(false));
        [$mug, $bowl] = array_map(Values::of(...), $desired);

        self::assertSame('Speckled mug', $mug->string('title'));
        self::assertTrue($mug->bool('simple'));
        self::assertSame(['gid://webflow/Category/64f1a2b3c4d5e6f708193c01'], $mug->strings('collections'));
        self::assertSame([['url' => self::MEDIA . '/wf-mug.png', 'alt' => null]], $mug->raw('images'));
        self::assertSame(['/product/speckled-mug'], $desired[0]->legacyPaths);
        $only = $mug->records('variants')[0];
        self::assertSame('gid://webflow/Variant/64f1a2b3c4d5e6f708195e01', $only->string('gid'), 'the SKU, as an order item names it');
        self::assertSame('34', $only->string('regular_price'), 'a compare-at price above the price is the regular price');
        self::assertSame('28', $only->nullableString('sale_price'), 'minor units (cents) to dollars');
        self::assertTrue($only->bool('manage_stock'));
        self::assertSame(12, $only->nullableInt('stock_quantity'));
        self::assertFalse($only->bool('virtual'));
        self::assertTrue($only->bool('taxable'));
        self::assertSame('FH-MUG-1', $only->nullableString('sku'));

        self::assertFalse($bowl->bool('simple'));
        self::assertSame([['name' => 'Glaze', 'position' => 1, 'values' => ['Ash', 'Celadon']]], $bowl->raw('attributes'));
        [$ash, $celadon] = $bowl->records('variants');
        self::assertSame(['Glaze' => 'Ash'], $ash->stringMap('options'));
        self::assertSame('42', $ash->string('regular_price'));
        self::assertNull($ash->nullableString('sale_price'));
        self::assertSame(self::MEDIA . '/wf-bowl-ash.png', $ash->nullableString('image'));
        self::assertFalse($celadon->bool('manage_stock'), 'infinite inventory is unmanaged');
        self::assertNull($celadon->nullableInt('stock_quantity'));
        $details = $bowl->record('product') ?? self::fail('the product details are meta');
        self::assertSame('physical', $details->string('product_type'));
        self::assertSame('standard-taxable', $details->string('tax-category'));
    }

    public function testADigitalOrUntaxedProduct(): void
    {
        $node = new Node([
            'id' => 'gid://webflow/Product/p1',
            'fieldData' => ['name' => 'Glazing guide', 'slug' => 'glazing-guide', 'sku-properties' => [], 'tax-category' => 'standard-exempt', 'ec-product-type' => 'f22027db68002190aef89a4a2b7ac8a1'],
            'skus' => [['id' => 'gid://webflow/Variant/s1', 'fieldData' => ['name' => 'Glazing guide', 'slug' => 'glazing-guide', 'price' => ['value' => 900, 'unit' => 'CAD'], 'ec-sku-billing-method' => 'subscription']]],
        ]);

        $desired = (new ProductMapper(true))->map($node);
        $variant = Values::of($desired)->records('variants')[0];

        self::assertTrue($variant->bool('virtual'), 'a digital product needs no shipping');
        self::assertFalse($variant->bool('taxable'));
        self::assertSame('webflow-s1', $variant->nullableString('sku'), 'a generated SKU');
        self::assertNotEmpty(array_filter($desired->warnings, static fn(string $warning): bool => str_contains($warning, 'subscription') && str_contains($warning, 'Deck card 396')), 'a subscription is reported, planned on its card');
    }

    public function testCustomers(): void
    {
        $desired = $this->desired('v2', Kind::Customer, new CustomerMapper());
        [$ada] = array_map(Values::of(...), $desired);

        self::assertSame('ada.thorne@example.com', $ada->nullableString('email'));
        self::assertSame('webflow-' . substr(hash('sha256', 'ada.thorne@example.com'), 0, 20), $ada->string('username'));
        self::assertSame('Ada Thorne', $ada->nullableString('first_name'));
        self::assertSame('2026-09-02T17:30:00Z', $ada->nullableString('created_at'));
        self::assertSame('V8V 1A1', $ada->record('shipping')?->string('postcode'));
        self::assertSame('BC', $ada->record('billing')?->string('state'));
    }

    public function testOrders(): void
    {
        [$first, $second] = array_map(Values::of(...), $this->desired('v1', Kind::Order, new OrderMapper()));

        self::assertSame('a3f9c1', $first->string('name'));
        self::assertSame('completed', $first->string('status'));
        self::assertSame('CAD', $first->string('currency'));
        self::assertSame(Fields::customerId('ada.thorne@example.com'), $first->nullableString('customer_gid'));
        self::assertSame('110.25', $first->string('total'));
        self::assertSame('10', $first->string('discount_total'), 'the discount, as a positive amount');
        self::assertSame('15', $first->string('shipping_total'));
        self::assertSame('5.25', $first->string('tax_total'));
        self::assertSame([['title' => 'GST', 'rate' => null, 'order_amount' => '5.25']], $first->raw('tax_lines'), 'a tax reported only for the whole order');
        self::assertSame('Canada Post Expedited', $first->records('shipping_lines')[0]->string('title'));
        [$mugs, $bowl] = $first->records('line_items');
        self::assertSame('gid://webflow/Product/64f1a2b3c4d5e6f708194d01', $mugs->nullableString('product_gid'));
        self::assertSame('gid://webflow/Variant/64f1a2b3c4d5e6f708195e01', $mugs->nullableString('variant_gid'));
        self::assertSame(2, $mugs->int('quantity'));
        self::assertSame('56', $mugs->string('total'));
        self::assertSame(['Variant' => 'Ramen bowl Celadon'], $bowl->stringMap('attributes'));
        self::assertSame('ch_wf_a3f9c1', $first->nullableString('transaction_id'));
        self::assertSame('Please wrap them separately.', $first->nullableString('customer_note'));
        self::assertSame('2026-09-03T18:00:00Z', $first->nullableString('closed_at'));
        self::assertSame('Canada Post tracking CP123456789CA', $first->records('notes')[0]->string('message'));
        self::assertSame([], $first->records('refunds'));
        self::assertSame('processing', $second->string('status'), 'paid and unfulfilled');
        $pickup = $second->records('shipping_lines')[0];
        self::assertSame(['Local pickup', '0'], [$pickup->string('title'), $pickup->string('total')], 'local pickup is a free shipping line');
    }

    public function testARefundedOrderHasOneRefundOfWhatWasPaid(): void
    {
        $refunded = Values::of($this->desired('v2', Kind::Order, new OrderMapper())[0]);

        self::assertSame('refunded', $refunded->string('status'));
        $refund = $refunded->records('refunds')[0];
        self::assertSame('110.25', $refund->string('amount'));
        self::assertSame('requested_by_customer', $refund->nullableString('note'));
        self::assertSame('2026-09-12T18:00:00Z', $refund->nullableString('created_at'));
    }

    public function testPages(): void
    {
        $desired = $this->desired('v1', Kind::Page, new PageMapper());
        $about = Values::of($desired[0]);

        self::assertSame('About the studio', $about->string('title'));
        self::assertSame('about', $about->string('slug'));
        self::assertSame(
            "<h1>About the studio</h1>\n<p>We throw every piece by hand in Fernhill.</p>\n<img src=\"http://webflow-fake:8080/images/wf-studio.png\" alt=\"The studio&#039;s kiln room\">\nVisit us Saturdays.",
            $about->string('content_html'),
            'text, the image with its asset, and the component text, in order',
        );
        self::assertSame(['title' => 'About Fernhill Ceramics', 'description' => 'A small pottery studio on Vancouver Island.'], $about->raw('seo'));
        self::assertSame('publish', $about->string('status'));
        self::assertCount(1, $about->records('form_controls'), 'the submit button is kept as meta');
        self::assertSame(['/about'], $desired[0]->legacyPaths);
    }

    public function testTheBlogAndItsPosts(): void
    {
        $reader = $this->reader('v1');
        $blog = (new BlogMapper())->map($reader->page(Kind::Blog, null, null, '')->nodes[0]);
        $posts = array_map(static fn(Node $node): Desired => (new ArticleMapper())->map($node), $reader->page(Kind::Article, null, null, '')->nodes);
        [$kiln, $draft] = array_map(Values::of(...), $posts);

        self::assertSame('Journal', Values::of($blog)->string('name'));
        self::assertSame(['/journal'], $blog->legacyPaths);
        self::assertSame('Firing the anagama', $kiln->string('title'));
        self::assertSame('<p>Five days of stoking.</p>', $kiln->string('content_html'));
        self::assertSame(['url' => self::MEDIA . '/wf-kiln.png', 'alt' => 'The kiln at night'], $kiln->record('image')?->all());
        self::assertSame('gid://webflow/Collection/64f1a2b3c4d5e6f708192b04', $kiln->string('blog_gid'));
        self::assertSame('2026-08-10T16:00:00Z', $kiln->string('published_at'));
        self::assertSame(['post-summary' => 'A week at the wood kiln.', 'author' => 'gid://webflow/Item/64f1a2b3c4d5e6f708199c01'], $kiln->raw('fields'), 'other fields as meta, references as identities');
        self::assertSame(['/journal/firing-the-anagama'], $posts[0]->legacyPaths);
        self::assertSame('draft', $draft->string('status'));
    }

    public function testContentTypesAndItems(): void
    {
        $reader = $this->reader('v1');
        $type = Values::of((new CollectionMapper())->map($reader->page(Kind::ContentType, null, null, '')->nodes[0]));
        $item = (new ItemMapper())->map($reader->page(Kind::ContentItem, null, null, '')->nodes[0]);
        $isla = Values::of($item);

        self::assertSame('Team', $type->string('name'));
        self::assertSame('Team Member', $type->string('singular_name'));
        self::assertSame('team', $type->string('slug'));
        self::assertCount(6, $type->records('collection_fields'));
        self::assertSame('gid://webflow/Collection/64f1a2b3c4d5e6f708192b05', $isla->string('type_gid'));
        self::assertSame('Isla Fenn', $isla->string('title'));
        self::assertSame('<p>Isla founded the studio.</p>', $isla->nullableString('content_html'));
        self::assertSame(['role' => 'Potter', 'email' => 'isla@fernhill.example'], $isla->raw('fields'));
        self::assertSame(['/team/isla-fenn'], $item->legacyPaths);
    }

    public function testRedirects(): void
    {
        $desired = $this->desired('v1', Kind::UrlRedirect, new RedirectMapper());

        self::assertSame(['/shop/mugs'], $desired[0]->legacyPaths);
        self::assertSame('/category/mugs', $desired[0]->redirectTarget, 'a path on the site resolves to its mirrored object');
        self::assertSame('https://studio.example.org/fernhill', $desired[1]->redirectTarget);
    }

    public function testARedirectFromTheHomePageIsNotMirrored(): void
    {
        $this->expectException(Unsupported::class);
        (new RedirectMapper())->map(new Node(['id' => 'gid://webflow/Redirect/r', 'fromUrl' => '/', 'toUrl' => '/about']));
    }

    /** @return list<Desired> */
    private function desired(string $state, Kind $kind, Mapper $mapper): array
    {
        $desired = [];
        foreach ($this->reader($state)->page($kind, null, null, '')->nodes as $node) {
            $mapped = $mapper->map($node);
            self::assertNotNull($mapped);
            $desired[] = $mapped;
        }

        return $desired;
    }

    private function reader(string $state): WebflowReader
    {
        return WebflowReader::connect('https://api.webflow.test/v2', '64f1a2b3c4d5e6f708192a3b', '64f1a2b3c4d5e6f708192b04', BearerAuthorizer::token('t', 'Webflow'), new ScriptedTransport(new FixtureResponder('webflow', $state)), new RecordingSleeper());
    }
}
