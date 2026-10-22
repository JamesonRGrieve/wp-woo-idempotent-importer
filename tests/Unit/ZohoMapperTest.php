<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\Zoho\Mapping\AccountMapper;
use IdempotentImporter\Source\Zoho\Mapping\ActivityMapper;
use IdempotentImporter\Source\Zoho\Mapping\BoardMapper;
use IdempotentImporter\Source\Zoho\Mapping\CampaignMapper;
use IdempotentImporter\Source\Zoho\Mapping\CategoryMapper;
use IdempotentImporter\Source\Zoho\Mapping\ContactMapper;
use IdempotentImporter\Source\Zoho\Mapping\CustomerMapper;
use IdempotentImporter\Source\Zoho\Mapping\DealMapper;
use IdempotentImporter\Source\Zoho\Mapping\Fields;
use IdempotentImporter\Source\Zoho\Mapping\OrderMapper;
use IdempotentImporter\Source\Zoho\Mapping\ProductMapper;
use IdempotentImporter\Source\Zoho\Mapping\TagMapper;
use IdempotentImporter\Source\Zoho\Mapping\TaskMapper;
use IdempotentImporter\Source\Zoho\ZohoReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class ZohoMapperTest extends TestCase
{
    public function testAccounts(): void
    {
        [$fernhill, $kilnworks] = array_map(Values::of(...), $this->desired('v1', Kind::CrmCompany, new AccountMapper()));

        self::assertSame(['Fernhill Ceramics', 'https://fernhill.example', 'Retail', 'Customer', 4], [$fernhill->string('name'), $fernhill->nullableString('website'), $fernhill->nullableString('industry'), $fernhill->nullableString('type'), $fernhill->nullableInt('employees_number')]);
        self::assertSame(['address_line_1' => '88 Kiln Road', 'city' => 'Victoria', 'state' => 'BC', 'postal_code' => 'V8V 2B2', 'country' => 'Canada'], $fernhill->record('address')?->all());
        self::assertSame([['key' => 'zoho_wholesale_tier', 'label' => 'Wholesale tier', 'type' => 'select-one', 'options' => ['Gold', 'Silver'], 'value' => 'Gold']], $fernhill->raw('custom_fields'));
        self::assertSame('Active', $fernhill->record('account')?->string('Rating'), 'other settable fields are meta');
        self::assertSame(['city' => 'Nanaimo'], $kilnworks->record('address')?->all());
    }

    public function testCampaignsTagsAndCategories(): void
    {
        [$market] = array_map(Values::of(...), $this->desired('v1', Kind::CrmList, new CampaignMapper()));
        [$wholesale] = array_map(Values::of(...), $this->desired('v1', Kind::CrmTag, new TagMapper()));
        [$clay] = array_map(Values::of(...), $this->desired('v1', Kind::Collection, new CategoryMapper()));

        self::assertSame(['Autumn market', 'The October market.'], [$market->string('title'), $market->nullableString('description')]);
        self::assertSame(['Wholesale', '#57B1FD'], [$wholesale->string('title'), $wholesale->nullableString('color')]);
        self::assertSame('Clay', $clay->string('name'));
    }

    public function testContactsAndLeads(): void
    {
        $reader = $this->reader('v2');
        $contacts = $reader->page(Kind::Contact, null, null, '');
        $leads = $reader->page(Kind::Contact, $contacts->endCursor, null, '');
        $ada = Values::of((new ContactMapper())->map($contacts->nodes[0]));
        $bo = Values::of((new ContactMapper())->map($contacts->nodes[1]));
        $cy = Values::of((new ContactMapper())->map($leads->nodes[0]));

        self::assertSame(['ada@zoho.example', 'Ms.', 'Ada', 'Thorne', '1988-04-12'], [$ada->string('email'), $ada->nullableString('prefix'), $ada->nullableString('first_name'), $ada->nullableString('last_name'), $ada->nullableString('date_of_birth')]);
        self::assertSame(['transactional', 'Contact', 'Zoho CRM'], [$ada->string('status'), $ada->string('record_type'), $ada->string('source')]);
        self::assertSame('V8V 1A1', $ada->record('address')?->string('postal_code'));
        self::assertSame(['gid://zoho/Account/5725767000011373001'], $ada->strings('company_gids'));
        self::assertSame(['gid://zoho/Campaign/5725767000011360001'], $ada->strings('list_gids'));
        self::assertSame(['gid://zoho/Tag/5725767000011390001'], $ada->strings('tag_gids'));
        self::assertSame([
            ['key' => 'zoho_favorite_glaze', 'label' => 'Favourite glaze', 'type' => 'select-one', 'options' => ['Ash', 'Celadon'], 'value' => 'Celadon'],
            ['key' => 'zoho_kiln_sizes', 'label' => 'Kiln sizes', 'type' => 'select-multi', 'options' => ['Small', 'Large'], 'value' => ['Small', 'Large']],
            ['key' => 'zoho_wholesale', 'label' => 'Wholesale buyer', 'type' => 'select-one', 'options' => ['Yes', 'No'], 'value' => 'Yes'],
        ], $ada->raw('custom_fields'));
        self::assertSame(['unsubscribed', '+1 250 555 0122'], [$bo->string('status'), $bo->nullableString('phone')], 'opted out; the mobile without a phone');
        self::assertSame(['Lead', 'Montréal', []], [$cy->string('record_type'), $cy->record('address')?->string('city'), $cy->strings('company_gids')]);
        self::assertSame('Moreau Pottery', $cy->record('contact')?->string('Company'));
    }

    public function testARecordWithoutAnEmailGetsAPlaceholder(): void
    {
        $contact = Values::of((new ContactMapper())->map($this->reader('v1')->page(Kind::Contact, null, null, '')->nodes[2]));

        self::assertSame(['record-5725767000011356003@zoho.invalid', true], [$contact->string('email'), $contact->bool('email_placeholder')]);
    }

    public function testNotesCallsAndMeetings(): void
    {
        $reader = $this->reader('v1');
        $nodes = [];
        $cursor = null;
        do {
            $page = $reader->page(Kind::CrmNote, $cursor, null, '');
            $nodes = [...$nodes, ...$page->nodes];
            $cursor = $page->endCursor;
        } while ($page->hasNextPage);
        [$note, $call, $meeting] = array_map(static fn(Node $node): Values => Values::of((new ActivityMapper())->map($node)), $nodes);

        self::assertSame(['note', 'Glaze preference', '<p>Prefers celadon.</p>', 'gid://zoho/Contact/5725767000011356001', '2026-08-01T16:00:00Z'], [$note->string('type'), $note->string('title'), $note->string('description_html'), $note->nullableString('contact_gid'), $note->string('created_at')]);
        self::assertSame(['call', 'Wholesale follow-up', '2026-08-16T17:00:00Z'], [$call->string('type'), $call->string('title'), $call->string('created_at')]);
        self::assertStringContainsString('<dt>Duration</dt><dd>12:34</dd>', $call->string('description_html'));
        self::assertSame(['meeting', 'gid://zoho/Account/5725767000011373002'], [$meeting->string('type'), $meeting->nullableString('company_gid')]);
        self::assertSame('5725767000000411001', $note->nullableString('owner_id'));
    }

    public function testProducts(): void
    {
        [$clay, $kit] = array_map(Values::of(...), $this->desired('v1', Kind::Product, new ProductMapper(false)));

        $variant = $clay->records('variants')[0];
        self::assertSame(['42.5', 'ZH-CLAY', 30, true], [$variant->string('regular_price'), $variant->nullableString('sku'), $variant->nullableInt('stock_quantity'), $variant->bool('manage_stock')]);
        self::assertSame([Fields::categoryId('Clay')], $clay->strings('collections'));
        self::assertSame(['private', false], [$kit->string('status'), $kit->records('variants')[0]->bool('taxable')], 'an inactive, untaxed product');
        self::assertFalse($kit->records('variants')[0]->bool('manage_stock'), 'no stock in hand: unmanaged');
    }

    public function testCustomersAndSalesOrders(): void
    {
        [$ada] = array_map(Values::of(...), $this->desired('v1', Kind::Customer, new CustomerMapper()));
        [$order] = array_map(Values::of(...), $this->desired('v1', Kind::Order, new OrderMapper()));

        self::assertSame(['ada@zoho.example', 'zoho-5725767000011356001'], [$ada->nullableString('email'), $ada->string('username')]);
        self::assertSame(['SO-00101', 'CAD', 'completed', '101', '5', '4.75'], [$order->string('name'), $order->string('currency'), $order->string('status'), $order->string('total'), $order->string('discount_total'), $order->string('tax_total')]);
        self::assertSame('gid://zoho/Customer/5725767000011356001', $order->nullableString('customer_gid'));
        self::assertSame([['name' => 'Adjustment', 'total' => '1.25', 'taxes' => []]], $order->raw('fees'));
        [$clay, $kit] = $order->records('line_items');
        self::assertSame(['gid://zoho/Product/5725767000011358001', 2, '85', '80'], [$clay->nullableString('product_gid'), $clay->int('quantity'), $clay->string('subtotal'), $clay->string('total')], 'the discount off the line total');
        self::assertSame(['Line taxes' => 'GST 5%'], $clay->stringMap('attributes'));
        self::assertSame('15', $kit->string('total'));
        self::assertSame('FedEx', $order->record('order')?->string('Carrier'));
    }

    public function testBoards(): void
    {
        [$standard, $tasks] = array_map(Values::of(...), $this->desired('v1', Kind::CrmPipeline, new BoardMapper()));

        self::assertSame('Standard', $standard->string('title'));
        self::assertSame([
            ['key' => 'Qualification', 'title' => 'Qualification', 'closed' => false, 'outcome' => null, 'probability' => null],
            ['key' => 'Negotiation/Review', 'title' => 'Negotiation', 'closed' => false, 'outcome' => null, 'probability' => null],
            ['key' => 'Closed Won', 'title' => 'Closed Won', 'closed' => true, 'outcome' => 'won', 'probability' => null],
            ['key' => 'Closed Lost', 'title' => 'Closed Lost', 'closed' => true, 'outcome' => 'lost', 'probability' => null],
        ], $standard->raw('stages'), 'in sequence; the forecast type says which close their deals, won or lost');
        self::assertSame('Omitted', $standard->stringMap('forecast_categories')['Closed Lost']);
        self::assertSame(['Not Started', 'Deferred', 'In Progress', 'Completed', 'Waiting for input'], array_map(static fn(Values $stage): string => $stage->string('key'), $tasks->records('stages')));
        self::assertTrue($tasks->records('stages')[3]->bool('closed'));
    }

    public function testDeals(): void
    {
        [$mugs, $kiln] = array_map(Values::of(...), $this->desired('v1', Kind::CrmDeal, new DealMapper()));

        self::assertSame(['gid://zoho/Pipeline/5725767000000135001', 'Negotiation/Review', 'open'], [$mugs->string('board_gid'), $mugs->string('stage_key'), $mugs->string('status')]);
        self::assertSame(['1500.50', 'CAD', '2026-10-15'], [$mugs->nullableString('lead_value'), $mugs->nullableString('currency'), $mugs->nullableString('due_at')], "in the org's currency; the closing date a date");
        self::assertSame(['gid://zoho/Contact/5725767000011356001'], $mugs->strings('contact_gids'));
        self::assertSame(['gid://zoho/Account/5725767000011373001'], $mugs->strings('company_gids'));
        self::assertSame(['id' => '5725767000000411001', 'email' => 'e2e@example.com', 'name' => 'Isla Fenn'], $mugs->raw('owner'));
        self::assertSame(['Kiln_Count' => 2], $mugs->raw('custom'));
        self::assertSame(['Type' => 'New Business'], $mugs->raw('deal'));
        self::assertSame(['closed', 'lost', '2026-09-20'], [$kiln->string('status'), $kiln->nullableString('outcome'), $kiln->nullableString('completed_at')]);

        $won = Values::of($this->desired('v2', Kind::CrmDeal, new DealMapper())[0]);
        self::assertSame(['Closed Won', 'closed', 'won'], [$won->string('stage_key'), $won->string('status'), $won->nullableString('outcome')]);
    }

    public function testTasks(): void
    {
        $visit = Values::of($this->desired('v1', Kind::CrmTask, new TaskMapper())[0]);

        self::assertSame(['gid://zoho/TaskBoard/tasks', 'Not Started', 'open', 'high', '2026-10-30'], [$visit->string('board_gid'), $visit->string('stage_key'), $visit->string('status'), $visit->nullableString('priority'), $visit->nullableString('due_at')]);
        self::assertSame(['gid://zoho/Contact/5725767000011356001'], $visit->strings('contact_gids'));
        $done = Values::of($this->desired('v2', Kind::CrmTask, new TaskMapper())[0]);
        self::assertSame(['closed', '2026-10-20T18:00:00Z'], [$done->string('status'), $done->nullableString('completed_at')]);
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

    private function reader(string $state): ZohoReader
    {
        return ZohoReader::connect('https://www.zohoapis.test', BearerAuthorizer::token('t', 'Zoho CRM'), new ScriptedTransport(new FixtureResponder('zoho', $state)), new RecordingSleeper());
    }
}
