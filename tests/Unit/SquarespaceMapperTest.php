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
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Squarespace\Mapping\DiscountMapper;
use IdempotentImporter\Source\Squarespace\Mapping\Fields;
use IdempotentImporter\Source\Squarespace\Mapping\OrderMapper;
use IdempotentImporter\Source\Squarespace\Mapping\ProductMapper;
use IdempotentImporter\Source\Squarespace\Mapping\ProfileMapper;
use IdempotentImporter\Source\Squarespace\Mapping\StorePageMapper;
use IdempotentImporter\Source\Squarespace\Mapping\WebsiteMapper;
use IdempotentImporter\Source\Squarespace\SquarespaceReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class SquarespaceMapperTest extends TestCase
{
    private const MEDIA = 'http://squarespace-fake:8080';
    private const KITCHEN = 'gid://squarespace/StorePage/6a1000000000000000000001';
    private const MARIN = 'gid://squarespace/Profile/6c3000000000000000000021';

    public function testWebsite(): void
    {
        $shop = Values::of((new WebsiteMapper())->map($this->reader('v1')->shop()));

        self::assertSame('Harbour & Pine', $shop->string('name'));
        self::assertNull($shop->nullableString('email'), 'the website profile has no store email');
        self::assertSame('CAD', $shop->string('currency'));
        self::assertSame('kg', $shop->nullableString('weight_unit'), 'metric');
        self::assertSame('America/Halifax', $shop->nullableString('timezone'));
        self::assertNull($shop->raw('prices_include_tax'));
        self::assertNull($shop->record('address'), 'only a country and region: the address settings are left alone');
        $store = $shop->record('store') ?? self::fail('the website details are kept');
        self::assertSame(['country' => 'CA', 'region' => 'NS'], $store->raw('location'));
        self::assertSame('https://harbourpine.example', $store->nullableString('url'));
        self::assertSame([], $shop->records('policies'));
    }

    public function testStorePages(): void
    {
        $desired = $this->desired('v1', Kind::Collection, new StorePageMapper());
        [$kitchen, $apparel] = array_map(Values::of(...), $desired);

        self::assertSame(self::KITCHEN, $desired[0]->gid);
        self::assertSame('Kitchen', $kitchen->string('name'));
        self::assertSame('kitchen', $kitchen->string('slug'));
        self::assertSame('', $kitchen->string('description_html'));
        self::assertNull($kitchen->record('image'));
        self::assertSame(['isEnabled' => true], $kitchen->raw('store_page'));
        self::assertSame(['/kitchen'], $desired[0]->legacyPaths, 'the store page URL redirects');
        self::assertSame('apparel', $apparel->string('slug'));
        $untitled = (new StorePageMapper())->map(new Node(['id' => 'gid://squarespace/StorePage/x', 'title' => 'Gifts & More']));
        self::assertSame('gifts-more', Values::of($untitled)->string('slug'), 'a slug from the title');
        self::assertSame([], $untitled->legacyPaths);
    }

    public function testProducts(): void
    {
        $nodes = $this->nodes('v1', Kind::Product);
        $mapper = new ProductMapper(false);
        $desired = array_map($mapper->map(...), array_slice($nodes, 0, 4));
        [$board, $scarf, $mug, $ebook] = array_map(Values::of(...), $desired);

        self::assertSame('Cedar cutting board', $board->string('title'));
        self::assertSame('cedar-cutting-board', $board->string('slug'));
        self::assertSame('<p>Hand-oiled Nova Scotia cedar.</p>', $board->string('description_html'));
        self::assertSame('publish', $board->string('status'));
        self::assertSame('2026-09-01T09:00:00Z', $board->nullableString('created_at'));
        self::assertSame(['kitchen', 'cedar'], $board->strings('tags'));
        self::assertSame([self::KITCHEN], $board->strings('collections'), 'its store page');
        self::assertSame(
            [['url' => self::MEDIA . '/img/board.png', 'alt' => 'Cedar cutting board'], ['url' => self::MEDIA . '/img/board-side.png', 'alt' => null]],
            $board->raw('images'),
            'by position, the first featured',
        );
        self::assertSame(['title' => 'Cedar cutting board | Harbour & Pine', 'description' => 'A cedar board for every kitchen.'], $board->seo());
        self::assertSame(['/kitchen/p/cedar-cutting-board'], $desired[0]->legacyPaths, 'the old product URL redirects');
        self::assertTrue($board->bool('simple'), 'one variant without attributes');
        self::assertSame([], $board->raw('attributes'));
        $only = $board->records('variants')[0];
        self::assertSame('gid://squarespace/ProductVariant/a1b2c3d4-0000-4000-8000-000000000111', $only->string('gid'));
        self::assertSame('SQS-BOARD-1', $only->nullableString('sku'));
        self::assertSame('0662233440017', $only->nullableString('gtin'));
        self::assertSame('48', $only->string('regular_price'), 'a JSON number, read exactly');
        self::assertNull($only->nullableString('sale_price'), 'not on sale');
        self::assertSame(['value' => '1.2', 'unit' => 'kg'], $only->record('weight')?->all());
        self::assertTrue($only->bool('manage_stock'));
        self::assertSame(9, $only->nullableInt('stock_quantity'));
        self::assertFalse($only->bool('virtual'));
        self::assertSame(
            ['a1b2c3d4-0000-4000-8000-000000000111' => ['mpn' => 'HP-CB-01', 'dimensions' => ['unit' => 'CENTIMETER', 'length' => 40, 'width' => 25, 'height' => 2]]],
            $board->raw('variant_details'),
        );
        self::assertSame('PHYSICAL', $board->record('product')?->string('type'));

        self::assertFalse($scarf->bool('simple'));
        self::assertSame([['name' => 'Colour', 'position' => 1, 'values' => ['Charcoal', 'Moss']]], $scarf->raw('attributes'));
        [$charcoal, $moss] = $scarf->records('variants');
        self::assertSame(['Colour' => 'Charcoal'], $charcoal->stringMap('options'));
        self::assertSame('Charcoal', $charcoal->string('title'));
        self::assertSame('65', $charcoal->string('regular_price'));
        self::assertSame('55', $charcoal->nullableString('sale_price'), 'on sale');
        self::assertSame(0, $moss->nullableInt('stock_quantity'));
        self::assertSame(self::MEDIA . '/img/scarf-moss.png', $moss->nullableString('image'));
        self::assertNull($charcoal->nullableString('image'));

        self::assertSame('private', $mug->string('status'), 'a hidden product is kept, private');
        $mugVariant = $mug->records('variants')[0];
        self::assertFalse($mugVariant->bool('manage_stock'), 'unlimited stock is untracked');
        self::assertNull($mugVariant->nullableInt('stock_quantity'));
        self::assertNull($mugVariant->nullableString('sku'));
        self::assertSame(['"Prototype mug" has 1 variant(s) without a SKU; WooCommerce Square needs one on each.'], $desired[2]->warnings);
        self::assertSame('squarespace-a1b2c3d4-0000-4000-8000-000000000131', Values::of((new ProductMapper(true))->map($nodes[2]))->records('variants')[0]->nullableString('sku'));

        self::assertTrue($ebook->bool('simple'));
        $download = $ebook->records('variants')[0];
        self::assertSame('gid://squarespace/ProductVariant/6b2000000000000000000014', $download->string('gid'), 'a download is keyed by its product');
        self::assertTrue($download->bool('virtual'));
        self::assertSame('12', $download->string('regular_price'), 'priced at the product level');
        self::assertFalse($download->bool('manage_stock'));
        self::assertNull($download->record('weight'));
        self::assertSame(['id' => 'dg-ebook', 'filename' => 'harbour-kitchen.pdf'], $ebook->record('product')?->raw('digitalGood'));
        self::assertStringContainsString('is a download', implode(' ', $desired[3]->warnings));

        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('"Gift card" is a gift card');
        $mapper->map($nodes[4]);
    }

    public function testServicesAreVirtualAndPriceChangesLand(): void
    {
        [$board] = $this->desired('v2', Kind::Product, new ProductMapper(false), 1);
        self::assertSame('52', Values::of($board)->records('variants')[0]->string('regular_price'));

        $scarf = $this->nodes('v1', Kind::Product)[1];
        $service = Values::of((new ProductMapper(false))->map(new Node([...$scarf->raw(), 'type' => 'SERVICE'], $scarf->id())));
        self::assertTrue($service->records('variants')[0]->bool('virtual'));
    }

    public function testProfiles(): void
    {
        $nodes = $this->nodes('v1', Kind::Customer);
        $mapper = new ProfileMapper();
        $marin = Values::of($mapper->map($nodes[0]) ?? self::fail('a customer maps'));
        $nadia = Values::of($mapper->map($nodes[1]) ?? self::fail('an account holder maps'));

        self::assertSame('marin@example.com', $marin->nullableString('email'));
        self::assertSame('squarespace-6c3000000000000000000021', $marin->string('username'));
        self::assertSame('Marin', $marin->nullableString('first_name'));
        self::assertSame('Boudreau', $marin->nullableString('last_name'));
        self::assertSame('+1 902-555-0131', $marin->nullableString('phone'));
        self::assertSame('2026-09-01T08:00:00Z', $marin->nullableString('created_at'));
        self::assertSame([
            'first_name' => 'Marin', 'last_name' => 'Boudreau', 'address_1' => '14 Harbour Street', 'city' => 'Lunenburg',
            'state' => 'NS', 'postcode' => 'B0J 2C0', 'country' => 'CA', 'phone' => '+1 902-555-0131',
        ], $marin->record('billing')?->all());
        self::assertSame($marin->raw('billing'), $marin->raw('shipping'));
        self::assertSame(['hasAccount' => true, 'isCustomer' => true, 'acceptsMarketing' => true], $marin->raw('profile'), 'no volatile transaction summary');
        self::assertNull($nadia->record('billing'), 'no address');
        self::assertNull($mapper->map($nodes[2]), 'a mailing-list subscriber is not a customer');
    }

    public function testDiscounts(): void
    {
        $nodes = $this->nodes('v1', Kind::Discount);
        $mapper = new DiscountMapper();
        [$welcome, $fiveOff, $shipping, $scarf] = array_map(static fn(Node $node): Values => Values::of($mapper->map($node)), array_slice($nodes, 0, 4));

        self::assertSame('Welcome 10%', $welcome->string('title'));
        self::assertSame(['SQS-WELCOME10'], $welcome->strings('codes'));
        self::assertSame('percent', $welcome->string('discount_type'));
        self::assertSame('10', $welcome->string('amount'));
        self::assertTrue($welcome->bool('once_per_customer'));
        self::assertNull($welcome->nullableInt('usage_limit'), 'not limited');
        self::assertSame('2026-09-01T00:00:00Z', $welcome->string('starts_at'));
        self::assertSame('2026-12-31T23:59:59Z', $welcome->nullableString('expires_at'));
        self::assertNull($welcome->nullableString('minimum_subtotal'));
        self::assertSame(['subscriptionOptions' => ['type' => 'EXCLUDED'], 'paymentPlanOptions' => ['type' => 'NONE']], $welcome->raw('discount'));
        self::assertSame('fixed_cart', $fiveOff->string('discount_type'));
        self::assertSame('5', $fiveOff->string('amount'));
        self::assertSame('50', $fiveOff->nullableString('minimum_subtotal'), 'the cart total criteria');
        self::assertSame(100, $fiveOff->nullableInt('usage_limit'));
        self::assertNull($fiveOff->nullableString('expires_at'));
        self::assertTrue($shipping->bool('free_shipping'));
        self::assertSame('0', $shipping->string('amount'));
        self::assertSame('percent', $scarf->string('discount_type'));
        self::assertSame(['gid://squarespace/Product/6b2000000000000000000012'], $scarf->strings('product_gids'), 'a percentage off one product');
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('applied automatically');
        $mapper->map($nodes[4]);
    }

    public function testDiscountsWooCommerceCannotApplyAreUnsupported(): void
    {
        $discount = $this->nodes('v1', Kind::Discount)[1];
        $variants = [
            'buy x get y' => ['template' => ['type' => 'BUY_X_GET_Y_PERCENTAGE', 'discountPercentage' => 50]],
            'amount off a product' => ['criteria' => ['type' => 'PRODUCT', 'productId' => '6b2000000000000000000012']],
            'pickup only' => ['template' => ['type' => 'FREE_SHIPPING', 'appliesToFulfillmentOptionType' => 'PICKUP']],
            'one shipping option' => ['template' => ['type' => 'FREE_SHIPPING', 'selectedShippingOption' => ['id' => 'x']]],
            'buy x criteria' => ['criteria' => ['type' => 'BUY_X_GET_Y', 'eligibleForDiscountQuantity' => 1, 'requiredFullPriceQuantity' => 2]],
        ];
        $unsupported = [];
        foreach ($variants as $case => $change) {
            try {
                (new DiscountMapper())->map(new Node([...$discount->raw(), ...$change], $discount->id()));
            } catch (Unsupported) {
                $unsupported[] = $case;
            }
        }
        self::assertSame(array_keys($variants), $unsupported);
    }

    public function testOrders(): void
    {
        [$paid, $unpaid] = $this->mapAll('v1', Kind::Order, new OrderMapper());
        $products = $this->mapAll('v1', Kind::Product, new ProductMapper(false), 2);

        self::assertSame('#1001', $paid->string('name'));
        self::assertSame('2026-09-03T14:00:00Z', $paid->string('created_at'));
        self::assertSame('CAD', $paid->string('currency'), 'from the currency object the published schema declares');
        self::assertFalse($paid->bool('prices_include_tax'));
        self::assertSame(self::MARIN, $paid->nullableString('customer_gid'));
        self::assertSame('marin@example.com', $paid->nullableString('email'));
        self::assertSame('+1 902-555-0131', $paid->nullableString('phone'));
        self::assertSame('Lunenburg', $paid->record('billing')?->nullableString('city'));
        self::assertSame('B0J 2C0', $paid->record('shipping')?->nullableString('postcode'));
        self::assertSame(['SQS-FIVEOFF'], $paid->strings('coupons'));
        [$boardLine, $scarfLine] = $paid->records('line_items');
        self::assertSame('gid://squarespace/OrderLineItem/6f6000000000000000000301', $boardLine->string('gid'));
        self::assertSame('gid://squarespace/Product/6b2000000000000000000011', $boardLine->nullableString('product_gid'));
        self::assertSame($products[0]->records('variants')[0]->string('gid'), $boardLine->nullableString('variant_gid'), 'the same variant identity the product mapper gives');
        self::assertSame($products[1]->records('variants')[0]->string('gid'), $scarfLine->nullableString('variant_gid'));
        self::assertSame('96', $boardLine->string('subtotal'), '2 × 48');
        self::assertSame('96', $boardLine->string('total'), 'the discount is the order\'s');
        self::assertSame(2, $boardLine->int('quantity'));
        self::assertSame('SQS-BOARD-1', $boardLine->nullableString('sku'));
        self::assertSame(['Engraving' => 'M.B.'], $boardLine->stringMap('attributes'), 'product-form answers');
        self::assertSame(['Colour' => 'Charcoal'], $scarfLine->stringMap('attributes'));
        self::assertFalse($boardLine->bool('gift_card'));
        self::assertSame(
            [['title' => 'Canada Post Expedited', 'code' => null, 'source' => 'squarespace', 'total' => '12', 'taxes' => []]],
            $paid->raw('shipping_lines'),
        );
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'order_amount' => '7.9']], $paid->raw('tax_lines'), "the transactions' sale and shipping taxes, order-level");
        self::assertSame('5', $paid->string('discount_total'));
        self::assertSame('12', $paid->string('shipping_total'));
        self::assertSame('7.9', $paid->string('tax_total'));
        self::assertSame('165.9', $paid->string('total'));
        self::assertSame('completed', $paid->string('status'), 'fulfilled');
        self::assertSame('2026-09-03T14:01:00Z', $paid->nullableString('processed_at'), 'the payment time');
        self::assertSame('2026-09-05T11:00:00Z', $paid->nullableString('closed_at'));
        self::assertSame('STRIPE (VISA)', $paid->nullableString('payment_method_title'));
        self::assertSame('ch_000501', $paid->nullableString('transaction_id'));
        self::assertSame([], $paid->raw('refunds'));
        self::assertSame([
            [
                'gid' => 'gid://squarespace/Fulfillment/6d4000000000000000000031-0',
                'created_at' => '2026-09-05T11:00:00Z',
                'author' => null,
                'message' => 'Squarespace fulfillment: Canada Post Expedited Parcel SQS1234567890CA https://www.canadapost-postescanada.ca/track/SQS1234567890CA',
            ],
            ['gid' => 'gid://squarespace/InternalNote/6d4000000000000000000031-0', 'created_at' => '2026-09-03T14:00:00Z', 'author' => 'Staff', 'message' => 'Gift-wrap the board.'],
        ], $paid->raw('notes'));
        self::assertSame([['label' => 'How did you hear about us?', 'value' => 'A friend']], $paid->record('order')?->raw('formSubmission'));

        self::assertSame('pending', $unpaid->string('status'), 'not charged');
        self::assertNull($unpaid->nullableString('customer_gid'), 'a guest order');
        self::assertNull($unpaid->nullableString('processed_at'));
        self::assertNull($unpaid->nullableString('payment_method_title'));
        self::assertSame([], $unpaid->raw('shipping_lines'), 'no shipping');
        self::assertSame([['title' => 'Tax', 'rate' => null, 'order_amount' => '3.25']], $unpaid->raw('tax_lines'), 'no transactions: the tax total');
        self::assertSame('68.25', $unpaid->string('total'));
    }

    public function testRefundsAndNewOrders(): void
    {
        [$refunded, , $new] = $this->mapAll('v2', Kind::Order, new OrderMapper());
        $ebook = Values::of((new ProductMapper(false))->map($this->nodes('v2', Kind::Product)[2]));

        self::assertSame('completed', $refunded->string('status'));
        self::assertSame([[
            'gid' => 'gid://squarespace/Refund/b7c8d9e0-0000-4000-8000-000000009001',
            'created_at' => '2026-09-10T15:00:00Z',
            'note' => null,
            'amount' => '10',
            'lines' => [],
        ]], $refunded->raw('refunds'));
        self::assertSame('processing', $new->string('status'), 'paid, awaiting fulfillment');
        self::assertSame('gid://squarespace/Profile/6c3000000000000000000024', $new->nullableString('customer_gid'));
        $line = $new->records('line_items')[0];
        self::assertSame($ebook->records('variants')[0]->string('gid'), $line->nullableString('variant_gid'), "a download's line names only the product");
        self::assertSame('12.6', $new->string('total'));
    }

    public function testOrderStatusRules(): void
    {
        self::assertSame('cancelled', OrderMapper::status('CANCELED', 'REFUNDED'));
        self::assertSame('refunded', OrderMapper::status('FULFILLED', 'REFUNDED'));
        self::assertSame('failed', OrderMapper::status('PENDING', 'FAILED'));
        self::assertSame('completed', OrderMapper::status('FULFILLED', 'PAID'));
        self::assertSame('completed', OrderMapper::status('FULFILLED', null));
        self::assertSame('pending', OrderMapper::status('PENDING', 'PENDING'));
        self::assertSame('pending', OrderMapper::status('PENDING', 'NOT_CHARGED'));
        self::assertSame('on-hold', OrderMapper::status('PENDING', 'AUTHORIZED'));
        self::assertSame('processing', OrderMapper::status('PENDING', 'PAID'));
        self::assertSame('processing', OrderMapper::status('PENDING', 'PARTIALLY_PAID'));
        self::assertSame('processing', OrderMapper::status('PENDING', 'REFUND_PENDING'));
        self::assertSame('processing', OrderMapper::status('PENDING', null), 'no payment state');
    }

    public function testTestOrdersAndNonCustomerProfilesAreNotLinked(): void
    {
        $order = $this->nodes('v1', Kind::Order)[0];
        $raw = $order->raw();

        self::assertNull((new OrderMapper())->map(new Node([...$raw, 'testmode' => true], $order->id())), 'a test-mode order');
        $subscriber = Values::of((new OrderMapper())->map(new Node([...$raw, 'customer_profile' => ['id' => 'x', 'isCustomer' => false, 'hasAccount' => false]], $order->id())) ?? self::fail('maps'));
        self::assertNull($subscriber->nullableString('customer_gid'), 'a profile that is not mirrored');
    }

    public function testMoneyIsExactInEitherWireForm(): void
    {
        $documented = new Node(['a' => ['currency' => 'USD', 'value' => 49.99], 'b' => ['currency' => 'USD', 'value' => '0.10'], 'c' => ['value' => 1e2]]);
        $published = new Node(['currency' => ['currencyCode' => 'CAD', 'symbol' => '$'], 'value' => 19.99]);

        self::assertSame('49.99', Fields::money($documented, 'a'), 'a JSON number, as its JSON spelled it');
        self::assertSame('0.1', Fields::money($documented, 'b'), 'a decimal string');
        self::assertSame('100', Fields::money($documented, 'c'), 'an exponent expanded exactly');
        self::assertNull(Fields::money($documented, 'missing'));
        self::assertSame('0', Fields::moneyOrZero($documented, 'missing'));
        self::assertSame('USD', Fields::currency($documented->requiredChild('a')), "the docs' bare code");
        self::assertSame('CAD', Fields::currency($published), "the schema's currency object");
        self::assertNull(Fields::currency($documented->requiredChild('c')));
        self::assertSame('lbs', Fields::weightUnit('POUND'));
        self::assertNull(Fields::weightUnit('STONE'));
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

    private function reader(string $state): SquarespaceReader
    {
        return SquarespaceReader::connect(
            'https://api.squarespace.test',
            'fixture-api-key',
            new ScriptedTransport(new FixtureResponder('squarespace', $state)),
            new RecordingSleeper(),
            new DateTimeImmutable('2026-09-12T12:00:00Z'),
        );
    }
}
