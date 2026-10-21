<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\HubSpot\HubSpotReader;
use IdempotentImporter\Source\HubSpot\Mapping\CompanyMapper;
use IdempotentImporter\Source\HubSpot\Mapping\ContactMapper;
use IdempotentImporter\Source\HubSpot\Mapping\CustomerMapper;
use IdempotentImporter\Source\HubSpot\Mapping\EngagementMapper;
use IdempotentImporter\Source\HubSpot\Mapping\ListMapper;
use IdempotentImporter\Source\HubSpot\Mapping\OrderMapper;
use IdempotentImporter\Source\HubSpot\Mapping\ProductMapper;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class HubSpotMapperTest extends TestCase
{
    public function testContacts(): void
    {
        $nodes = $this->reader('v1')->page(Kind::Contact, null, null, '')->nodes;
        $ada = Values::of((new ContactMapper())->map($nodes[0]));
        $bo = Values::of((new ContactMapper())->map($nodes[1]));

        self::assertSame('ada.thorne@example.com', $ada->string('email'), 'lower-cased');
        self::assertSame(['Ada', 'Thorne', '+1 250 555 0111'], [$ada->nullableString('first_name'), $ada->nullableString('last_name'), $ada->nullableString('phone')]);
        self::assertSame(['address_line_1' => '12 Cedar Lane', 'city' => 'Victoria', 'state' => 'BC', 'postal_code' => 'V8V 1A1', 'country' => 'Canada'], $ada->record('address')?->all());
        self::assertSame('transactional', $ada->string('status'), 'no recorded consent: no marketing');
        self::assertSame('customer', $ada->string('contact_type'));
        self::assertSame('HubSpot', $ada->string('source'));
        self::assertSame('2026-07-01T16:00:00Z', $ada->nullableString('created_at'));
        self::assertSame(['gid://hubspot/Company/9001', 'gid://hubspot/Company/9002'], $ada->strings('company_gids'), 'the first is the primary company');
        self::assertSame(['gid://hubspot/List/41', 'gid://hubspot/List/42'], $ada->strings('list_gids'));
        self::assertSame([
            ['key' => 'hubspot_favorite_glaze', 'label' => 'Favourite glaze', 'type' => 'select-one', 'options' => ['Ash', 'Celadon', 'Tenmoku'], 'value' => 'Celadon'],
            ['key' => 'hubspot_kiln_sizes', 'label' => 'Kiln sizes', 'type' => 'select-multi', 'options' => ['Small', 'Large'], 'value' => ['Small', 'Large']],
            ['key' => 'hubspot_member_since', 'label' => 'Studio member since', 'type' => 'date', 'options' => [], 'value' => '2025-03-01'],
            ['key' => 'hubspot_last_class_at', 'label' => 'Last class', 'type' => 'date_time', 'options' => [], 'value' => '2026-08-20 18:30:00'],
            ['key' => 'hubspot_studio_notes', 'label' => 'Studio notes', 'type' => 'textarea', 'options' => [], 'value' => 'Prefers morning firings.'],
        ], $ada->raw('custom_fields'), 'choices as their labels, multiple choices split, date-times in FluentCRM\'s format');
        self::assertSame(['company' => 'Fernhill Ceramics', 'jobtitle' => 'Potter', 'legacy_field' => 'x'], $ada->raw('contact'), 'other settable properties (a hidden one too) are meta; analytics and read-only ones are not mirrored');
        self::assertSame('lead', $bo->string('contact_type'));
        self::assertSame('+1 250 555 0122', $bo->nullableString('phone'), 'the mobile phone when there is no phone');
        self::assertSame([null, null, null, null, null], array_map(static fn(Values $field): mixed => $field->raw('value'), $bo->records('custom_fields')), 'the fields are defined, without values');
    }

    public function testAContactWithoutAnEmailIsNotMirrored(): void
    {
        $this->expectException(Unsupported::class);
        (new ContactMapper())->map($this->reader('v1')->page(Kind::Contact, null, null, '')->nodes[2]);
    }

    public function testCompanies(): void
    {
        [$fernhill, $kilnworks] = array_map(Values::of(...), $this->desired('v1', Kind::CrmCompany, new CompanyMapper()));

        self::assertSame('Fernhill Ceramics', $fernhill->string('name'));
        self::assertSame('https://fernhill.example', $fernhill->nullableString('website'), 'the domain as the website');
        self::assertSame(['ARTS_AND_CRAFTS', '+1 250 555 0101'], [$fernhill->nullableString('industry'), $fernhill->nullableString('phone')]);
        self::assertSame(['city' => 'Victoria', 'state' => 'BC'], $fernhill->record('address')?->all());
        self::assertSame([['key' => 'hubspot_wholesale_tier', 'label' => 'Wholesale tier', 'type' => 'select-one', 'options' => ['Gold', 'Silver'], 'value' => 'Gold']], $fernhill->raw('custom_fields'));
        self::assertSame(['numberofemployees' => '4'], $fernhill->raw('company'));
        self::assertNull($kilnworks->records('custom_fields')[0]->raw('value'), 'no value');
    }

    public function testListsAndSegments(): void
    {
        [$members, $segment] = array_map(Values::of(...), $this->desired('v1', Kind::CrmList, new ListMapper()));

        self::assertSame('Studio members', $members->string('title'));
        self::assertSame('MANUAL', $members->record('list')?->string('processingType'));
        self::assertSame('DYNAMIC', $segment->record('list')?->string('processingType'), 'a segment is a list whose membership follows HubSpot\'s');
    }

    public function testNotesCallsEmailsAndMeetings(): void
    {
        $reader = $this->reader('v1');
        $nodes = [];
        $cursor = null;
        do {
            $page = $reader->page(Kind::CrmNote, $cursor, null, '');
            $nodes = [...$nodes, ...$page->nodes];
            $cursor = $page->endCursor;
        } while ($page->hasNextPage);
        [$note, $companyNote, $call, $email, , $meeting] = array_map(static fn(Node $node): Values => Values::of((new EngagementMapper())->map($node)), $nodes);

        self::assertSame(['note', 'Note', '<p>Asked about a wholesale order of mugs.</p>'], [$note->string('type'), $note->string('title'), $note->string('description_html')]);
        self::assertSame('gid://hubspot/Contact/501', $note->nullableString('contact_gid'));
        self::assertSame('2026-08-15T17:00:00Z', $note->string('created_at'));
        self::assertSame('77', $note->nullableString('owner_id'));
        self::assertSame('gid://hubspot/Company/9001', $companyNote->nullableString('company_gid'), "the same note on the company's profile");
        self::assertNull($companyNote->nullableString('contact_gid'));
        self::assertSame(['call', 'Wholesale follow-up'], [$call->string('type'), $call->string('title')]);
        self::assertSame(
            '<p>Discussed pricing tiers.</p><p>Will send a quote.</p><dl><dt>Direction</dt><dd>OUTBOUND</dd><dt>Status</dt><dd>COMPLETED</dd><dt>From</dt><dd>+1 250 555 0001</dd><dt>To</dt><dd>+1 250 555 0111</dd><dt>Duration</dt><dd>00:12:34</dd></dl>',
            $call->string('description_html'),
            'plain text as paragraphs, then the details FluentCRM has no field for',
        );
        self::assertSame(['email', 'Your quote'], [$email->string('type'), $email->string('title')]);
        self::assertStringStartsWith('<p>Quote attached.</p>', $email->string('description_html'), "the email's HTML");
        self::assertSame(['meeting', 'Studio visit'], [$meeting->string('type'), $meeting->string('title')]);
        self::assertStringContainsString('<dt>Location</dt><dd>Fernhill studio</dd>', $meeting->string('description_html'));
    }

    public function testProducts(): void
    {
        $desired = $this->desired('v1', Kind::Product, new ProductMapper(false));
        [$clay, $class] = array_map(Values::of(...), $desired);

        self::assertSame('Stoneware clay, 10 kg', $clay->string('title'));
        self::assertSame("<p>Mid-fire stoneware.<br>\nWedged and ready.</p>", $clay->string('description_html'));
        self::assertTrue($clay->bool('simple'));
        $variant = $clay->records('variants')[0];
        self::assertSame(['gid://hubspot/Variant/7001', '42.5', 'HS-CLAY-10', '18.25'], [$variant->string('gid'), $variant->string('regular_price'), $variant->nullableString('sku'), $variant->nullableString('cost')]);
        self::assertFalse($variant->bool('manage_stock'), 'HubSpot keeps no stock');
        self::assertSame([], $desired[0]->warnings);
        self::assertSame(['hs_recurring_billing_period' => 'P12M'], $class->raw('product'));
        self::assertNotEmpty($desired[1]->warnings, 'recurring billing has no home yet');
        self::assertSame('hubspot-7002', Values::of((new ProductMapper(true))->map($this->reader('v1')->page(Kind::Product, null, null, '')->nodes[1]))->records('variants')[0]->nullableString('sku'));
    }

    public function testCustomers(): void
    {
        [$ada] = array_map(Values::of(...), $this->desired('v1', Kind::Customer, new CustomerMapper()));

        self::assertSame(['ada.thorne@example.com', 'hubspot-501', 'Ada'], [$ada->nullableString('email'), $ada->string('username'), $ada->nullableString('first_name')]);
        self::assertSame(['first_name' => 'Ada', 'last_name' => 'Thorne', 'company' => 'Fernhill Ceramics', 'address_1' => '12 Cedar Lane', 'city' => 'Victoria', 'state' => 'BC', 'postcode' => 'V8V 1A1', 'country' => 'Canada', 'phone' => '+1 250 555 0111'], $ada->record('billing')?->all());
    }

    public function testOrders(): void
    {
        $order = Values::of($this->desired('v1', Kind::Order, new OrderMapper())[0]);

        self::assertSame(['HS-1001', 'CAD', '2026-09-02T17:30:00Z'], [$order->string('name'), $order->string('currency'), $order->string('created_at')]);
        self::assertSame('gid://hubspot/Customer/501', $order->nullableString('customer_gid'));
        self::assertSame(['address_1' => '12 Cedar Lane', 'city' => 'Victoria', 'state' => 'BC', 'postcode' => 'V8V 1A1'], $order->record('shipping')?->all());
        [$clay, $kit] = $order->records('line_items');
        self::assertSame(['gid://hubspot/Product/7001', 2, '85', '85'], [$clay->nullableString('product_gid'), $clay->int('quantity'), $clay->string('subtotal'), $clay->string('total')]);
        self::assertNull($kit->nullableString('product_gid'), 'a line item without a product');
        self::assertSame('100', $order->string('total'), 'the sum of the line items');
        self::assertSame('processing', $order->string('status'));
        self::assertSame('Tracking CP123456789CA', $order->records('notes')[0]->string('message'));
        self::assertSame(['hs_fulfillment_status' => 'Shipped', 'hs_pipeline_stage' => '4b27b500', 'hs_source_store' => 'Studio'], $order->raw('order'), 'every other order property is meta');
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

    private function reader(string $state): HubSpotReader
    {
        return HubSpotReader::connect('https://api.hubapi.test', BearerAuthorizer::token('t', 'HubSpot'), new ScriptedTransport(new FixtureResponder('hubspot', $state)), new RecordingSleeper());
    }
}
