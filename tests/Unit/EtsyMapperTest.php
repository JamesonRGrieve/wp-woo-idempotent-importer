<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Etsy\EtsyConnection;
use IdempotentImporter\Source\Etsy\EtsyReader;
use IdempotentImporter\Source\Etsy\Mapping\BuyerMapper;
use IdempotentImporter\Source\Etsy\Mapping\Fields;
use IdempotentImporter\Source\Etsy\Mapping\ListingMapper;
use IdempotentImporter\Source\Etsy\Mapping\ReceiptMapper;
use IdempotentImporter\Source\Etsy\Mapping\ReviewMapper;
use IdempotentImporter\Source\Etsy\Mapping\SectionMapper;
use IdempotentImporter\Source\Etsy\Mapping\ShopMapper;
use IdempotentImporter\Source\Etsy\OAuth;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\EtsyResponder;
use IdempotentImporter\Tests\Support\InMemoryRefreshTokens;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class EtsyMapperTest extends TestCase
{
    public function testShop(): void
    {
        $shop = Values::of((new ShopMapper())->map($this->reader('v1')->shop()));

        self::assertSame('ClayAndCloth', $shop->string('name'));
        self::assertSame('CAD', $shop->string('currency'));
        self::assertNull($shop->nullableString('email'), 'Etsy has no store email, so WooCommerce keeps its own');
        self::assertSame('Handmade mugs & aprons', $shop->nullableString('title'));
        $policies = $shop->records('policies');
        self::assertSame(['Shipping policy', 'Privacy policy', 'Returns and exchanges'], array_map(static fn(Values $p): string => $p->string('title'), $policies));
        self::assertSame("<p>Ships within 3 days.</p>\n<p>Tracked shipping only.</p>", $policies[0]->string('content_html'));
        self::assertSame('privacy', $policies[1]->nullableString('role'));
        self::assertSame('<p>Returns and exchanges are accepted within 30 days.</p>', $policies[2]->string('content_html'));
        self::assertSame('gid://etsy/ReturnPolicy/41', $policies[2]->string('gid'));
    }

    public function testSections(): void
    {
        [$mugs] = $this->mapAll('v1', Kind::Collection, new SectionMapper());

        self::assertSame('Mugs', $mugs->string('name'));
        self::assertSame('mugs', $mugs->string('slug'));
        self::assertSame(1, $mugs->int('rank'));
    }

    public function testListings(): void
    {
        [$mug, $apron, $vase, $sample] = $this->mapAll('v1', Kind::Product, new ListingMapper(false));

        self::assertSame('Hand-thrown mug "Ocean"', $mug->string('title'));
        self::assertSame('hand-thrown-mug-ocean', $mug->string('slug'));
        self::assertSame("<p>Wheel-thrown stoneware.<br>\nDishwasher safe.</p>\n<p>Each one is unique.</p>", $mug->string('description_html'));
        self::assertSame('publish', $mug->string('status'));
        self::assertSame(['gid://etsy/Section/51'], $mug->strings('collections'));
        self::assertSame('http://etsy-fake:8080/il/6011.png', $mug->records('images')[0]->string('url'), 'images by rank');
        self::assertTrue($mug->bool('simple'));
        $variant = $mug->records('variants')[0];
        self::assertSame('gid://etsy/ListingVariant/601', $variant->string('gid'));
        self::assertSame('MUG-OCEAN', $variant->nullableString('sku'));
        self::assertSame('32', $variant->string('regular_price'));
        self::assertSame(['value' => '350', 'unit' => 'g'], $variant->record('weight')?->all());
        self::assertSame(4, $variant->nullableInt('stock_quantity'));
        self::assertSame(['stoneware'], $mug->strings('materials'), 'materials are mirrored as meta');

        self::assertFalse($apron->bool('simple'));
        self::assertSame([['name' => 'Color', 'position' => 1, 'values' => ['Natural', 'Charcoal']]], $apron->raw('attributes'));
        self::assertSame(
            ['gid://etsy/ListingVariant/602-1001', 'gid://etsy/ListingVariant/602-1002'],
            array_map(static fn(Values $v): string => $v->string('gid'), $apron->records('variants')),
        );
        self::assertSame(['Color' => 'Charcoal'], $apron->records('variants')[1]->stringMap('options'));
        self::assertSame(['value' => '0.4', 'unit' => 'kg'], $apron->records('variants')[0]->record('weight')?->all());

        self::assertSame('private', $vase->string('status'), 'an inactive listing is kept, hidden');
        self::assertSame('draft', $sample->string('status'));
        self::assertNull($sample->records('variants')[0]->nullableString('sku'));
    }

    public function testAListingWithoutASkuWarnsOrGetsAGeneratedOne(): void
    {
        $sample = $this->nodes('v1', Kind::Product)[3];

        self::assertSame(['"Glaze sample" has 1 variant(s) without a SKU; WooCommerce Square needs one on each.'], (new ListingMapper(false))->map($sample)->warnings);
        self::assertSame('etsy-603', Values::of((new ListingMapper(true))->map($sample))->records('variants')[0]->nullableString('sku'));
    }

    public function testReceipts(): void
    {
        [$shipped, $open] = $this->mapAll('v1', Kind::Order, new ReceiptMapper());
        $apronVariants = $this->mapAll('v1', Kind::Product, new ListingMapper(false))[1]->records('variants');

        self::assertSame('#801', $shipped->string('name'));
        self::assertSame('CAD', $shipped->string('currency'));
        self::assertSame('ada@example.com', $shipped->nullableString('email'));
        self::assertSame('gid://etsy/User/9001', $shipped->nullableString('customer_gid'));
        self::assertSame('completed', $shipped->string('status'), 'paid and shipped');
        self::assertSame(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'address_1' => '1 Main St', 'city' => 'Edmonton', 'state' => 'AB', 'postcode' => 'T5J 0N3', 'country' => 'CA'], $shipped->record('shipping')?->all());
        [$mugLine, $apronLine] = $shipped->records('line_items');
        self::assertSame('64', $mugLine->string('subtotal'), '2 × 32');
        self::assertSame('gid://etsy/Listing/601', $mugLine->nullableString('product_gid'));
        self::assertSame($apronVariants[1]->string('gid'), $apronLine->nullableString('variant_gid'), 'a line points at the variant the listing mapped');
        self::assertSame(['Color' => 'Charcoal'], $apronLine->stringMap('attributes'));
        self::assertSame([['title' => 'Sales tax', 'rate' => null, 'order_amount' => '10.4']], $shipped->raw('tax_lines'));
        self::assertSame('10.4', $shipped->string('tax_total'));
        self::assertSame('134.4', $shipped->string('total'));
        self::assertSame('Canada Post Expedited', $shipped->records('shipping_lines')[0]->string('title'));
        self::assertSame('Please wrap it as a gift', $shipped->nullableString('customer_note'));
        self::assertSame('Etsy shipment: Canada Post CP123456789CA', $shipped->records('notes')[0]->string('message'));

        self::assertSame('pending', $open->string('status'));
        self::assertNull($open->nullableString('processed_at'));
    }

    public function testRefundsAndNewReceipts(): void
    {
        [$refunded, , $new] = $this->mapAll('v2', Kind::Order, new ReceiptMapper());

        self::assertSame('completed', $refunded->string('status'), 'partially refunded after shipping');
        $refund = $refunded->records('refunds')[0];
        self::assertSame('10', $refund->string('amount'));
        self::assertSame('gid://etsy/Refund/801-' . (1788000000 + 5 * 86400), $refund->string('gid'));
        self::assertSame('Chipped handle', $refund->nullableString('note'));
        self::assertSame('processing', $new->string('status'), 'paid, not shipped');
    }

    public function testReceiptStatusRules(): void
    {
        self::assertSame('processing', ReceiptMapper::status('paid', false));
        self::assertSame('completed', ReceiptMapper::status('paid', true));
        self::assertSame('on-hold', ReceiptMapper::status('payment processing', false));
        self::assertSame('refunded', ReceiptMapper::status('fully refunded', true));
        self::assertSame('cancelled', ReceiptMapper::status('canceled', false));
        self::assertSame('pending', ReceiptMapper::status('something new', false));
    }

    public function testBuyers(): void
    {
        [$ada] = $this->mapAll('v1', Kind::Customer, new BuyerMapper());

        self::assertSame('etsy-9001', $ada->string('username'));
        self::assertSame('ada@example.com', $ada->nullableString('email'));
        self::assertNull($ada->nullableString('created_at'), 'Etsy has no account creation time for a buyer');
    }

    public function testReviews(): void
    {
        [$review] = $this->mapAll('v1', Kind::Review, new ReviewMapper());

        self::assertSame('gid://etsy/Listing/601', $review->nullableString('product_gid'));
        self::assertSame(5, $review->int('rating'));
        self::assertSame("<p>Lovely mug!<br>\nUse it daily.</p>", $review->string('content'));
        self::assertSame(ReviewMapper::AUTHOR, $review->string('author'));
        self::assertTrue($review->bool('verified'));
    }

    public function testMoneyNeedsADecimalDivisor(): void
    {
        self::assertSame('0.05', Fields::money(new Node(['price' => ['amount' => 5, 'divisor' => 100, 'currency_code' => 'CAD']]), 'price'));
        self::assertSame('7', Fields::money(new Node(['price' => ['amount' => 7, 'divisor' => 1, 'currency_code' => 'JPY']]), 'price'));
        $this->expectException(SourceError::class);
        Fields::money(new Node(['price' => ['amount' => 5, 'divisor' => 3, 'currency_code' => 'CAD']]), 'price');
    }

    /** @return list<Values> */
    private function mapAll(string $state, Kind $kind, Mapper $mapper): array
    {
        return array_map(static fn(Node $node): Values => Values::of($mapper->map($node) ?? self::fail('mapped to nothing')), $this->nodes($state, $kind));
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

    private function reader(string $state): EtsyReader
    {
        $transport = new ScriptedTransport(new EtsyResponder($state));
        $connection = new EtsyConnection('https://openapi.etsy.test', 'keystring', 'shared-secret');

        return EtsyReader::connect($connection, new OAuth($connection, $transport, new InMemoryRefreshTokens('refresh')), $transport, new RecordingSleeper());
    }
}
