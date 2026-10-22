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
use IdempotentImporter\Source\Salesforce\Mapping\AccountMapper;
use IdempotentImporter\Source\Salesforce\Mapping\ActivityMapper;
use IdempotentImporter\Source\Salesforce\Mapping\BoardMapper;
use IdempotentImporter\Source\Salesforce\Mapping\CampaignMapper;
use IdempotentImporter\Source\Salesforce\Mapping\ContactMapper;
use IdempotentImporter\Source\Salesforce\Mapping\CustomerMapper;
use IdempotentImporter\Source\Salesforce\Mapping\OpportunityMapper;
use IdempotentImporter\Source\Salesforce\Mapping\OrderMapper;
use IdempotentImporter\Source\Salesforce\Mapping\ProductMapper;
use IdempotentImporter\Source\Salesforce\Mapping\TaskMapper;
use IdempotentImporter\Source\Salesforce\Mapping\TopicMapper;
use IdempotentImporter\Source\Salesforce\SalesforceReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class SalesforceMapperTest extends TestCase
{
    public function testAccounts(): void
    {
        [$fernhill, $kilnworks] = array_map(Values::of(...), $this->desired('v1', Kind::CrmCompany, new AccountMapper()));

        self::assertSame(['Fernhill Ceramics', '+1 250 555 0101', 'https://fernhill.example', 'Retail', 'Customer', 4], [
            $fernhill->string('name'), $fernhill->nullableString('phone'), $fernhill->nullableString('website'), $fernhill->nullableString('industry'),
            $fernhill->nullableString('type'), $fernhill->nullableInt('employees_number'),
        ]);
        self::assertSame(['address_line_1' => '88 Kiln Road', 'address_line_2' => 'Unit 2', 'city' => 'Victoria', 'state' => 'BC', 'postal_code' => 'V8V 2B2', 'country' => 'Canada'], $fernhill->record('address')?->all(), "the billing street's two lines");
        self::assertSame([['key' => 'salesforce_wholesale_tier_c', 'label' => 'Wholesale tier', 'type' => 'select-one', 'options' => ['Gold', 'Silver'], 'value' => 'Gold']], $fernhill->raw('custom_fields'));
        self::assertSame(['Rating' => 'Hot', 'OwnerId' => '005Fh00000Own01AAA'], $fernhill->raw('account'), 'other settable fields are meta');
        self::assertSame(['city' => 'Nanaimo'], $kilnworks->record('address')?->all(), 'the shipping address when there is no billing address');
    }

    public function testCampaignsAndTopics(): void
    {
        [$members] = array_map(Values::of(...), $this->desired('v1', Kind::CrmList, new CampaignMapper()));
        [$wholesale] = array_map(Values::of(...), $this->desired('v1', Kind::CrmTag, new TopicMapper()));

        self::assertSame(['Studio members', 'Members of the studio.'], [$members->string('title'), $members->nullableString('description')]);
        self::assertSame('In Progress', $members->record('campaign')?->string('Status'));
        self::assertSame(['Wholesale', 'Buys for resale.'], [$wholesale->string('title'), $wholesale->nullableString('description')]);
    }

    public function testContactsAndLeads(): void
    {
        $reader = $this->reader('v2');
        $contacts = $reader->page(Kind::Contact, null, null, '');
        $leads = $reader->page(Kind::Contact, $contacts->endCursor, null, '');
        $ada = Values::of((new ContactMapper())->map($contacts->nodes[0]));
        $bo = Values::of((new ContactMapper())->map($contacts->nodes[1]));
        $cy = Values::of((new ContactMapper())->map($leads->nodes[0]));

        self::assertSame(['ada@fernhill.example', 'Ms.', 'Ada', 'Thorne', '1988-04-12'], [$ada->string('email'), $ada->nullableString('prefix'), $ada->nullableString('first_name'), $ada->nullableString('last_name'), $ada->nullableString('date_of_birth')]);
        self::assertSame(['transactional', 'lead', 'Contact'], [$ada->string('status'), $ada->string('contact_type'), $ada->string('record_type')]);
        self::assertSame('V8V 1A1', $ada->record('address')?->string('postal_code'), 'the mailing address');
        self::assertSame(['gid://salesforce/Account/001Fh00000Fern01AAA'], $ada->strings('company_gids'));
        self::assertSame(['gid://salesforce/Campaign/701Fh00000Mem001AAA', 'gid://salesforce/Campaign/701Fh00000Evt001AAA'], $ada->strings('list_gids'));
        self::assertSame(['gid://salesforce/Topic/0TOFh00000Whl001AAA'], $ada->strings('tag_gids'));
        self::assertSame([
            ['key' => 'salesforce_favorite_glaze_c', 'label' => 'Favourite glaze', 'type' => 'select-one', 'options' => ['Ash', 'Celadon'], 'value' => 'Celadon'],
            ['key' => 'salesforce_kiln_sizes_c', 'label' => 'Kiln sizes', 'type' => 'select-multi', 'options' => ['Small', 'Large'], 'value' => ['Small', 'Large']],
            ['key' => 'salesforce_wholesale_c', 'label' => 'Wholesale buyer', 'type' => 'select-one', 'options' => ['Yes', 'No'], 'value' => 'Yes'],
        ], $ada->raw('custom_fields'), 'a formula field is not a custom field to keep');
        self::assertSame(['Title' => 'Potter'], $ada->raw('contact'));
        self::assertSame('unsubscribed', $bo->string('status'), 'opted out of email');
        self::assertSame('+1 250 555 0122', $bo->nullableString('phone'), 'the mobile phone when there is no phone');
        self::assertSame(['Lead', 'lead', 'Montréal'], [$cy->string('record_type'), $cy->string('contact_type'), $cy->record('address')?->string('city')]);
        self::assertSame([], $cy->strings('company_gids'), "a lead's company is only a name (meta)");
        self::assertSame('Moreau Pottery', $cy->record('contact')?->string('Company'));
    }

    public function testARecordWithoutAnEmailIsNotMirrored(): void
    {
        $this->expectException(Unsupported::class);
        (new ContactMapper())->map($this->reader('v1')->page(Kind::Contact, null, null, '')->nodes[2]);
    }

    public function testActivities(): void
    {
        $reader = $this->reader('v1');
        $nodes = [];
        $cursor = null;
        do {
            $page = $reader->page(Kind::CrmNote, $cursor, null, '');
            $nodes = [...$nodes, ...$page->nodes];
            $cursor = $page->endCursor;
        } while ($page->hasNextPage);
        [$call, $email, $meeting, $note] = array_map(static fn(Node $node): Values => Values::of((new ActivityMapper())->map($node)), $nodes);

        self::assertSame(['call', 'Wholesale follow-up', 'gid://salesforce/Contact/003Fh00000Ada001AAA'], [$call->string('type'), $call->string('title'), $call->nullableString('contact_gid')]);
        self::assertSame(
            '<p>Discussed pricing tiers.</p><dl><dt>Status</dt><dd>Completed</dd><dt>Priority</dt><dd>Normal</dd><dt>Due</dt><dd>2026-08-16</dd><dt>Call type</dt><dd>Outbound</dd><dt>Duration (seconds)</dt><dd>754</dd><dt>Result</dt><dd>Left message</dd></dl>',
            $call->string('description_html'),
        );
        self::assertSame('001Fh00000Fern01AAA', $call->nullableString('related_to'), 'the account it relates to is meta');
        self::assertSame('email', $email->string('type'));
        self::assertSame(['meeting', 'gid://salesforce/Account/001Fh00000Kiln01AAA', '2026-08-18T17:00:00Z'], [$meeting->string('type'), $meeting->nullableString('company_gid'), $meeting->string('created_at')]);
        self::assertNull($meeting->nullableString('contact_gid'));
        self::assertSame(['note', 'Glaze preference', '<p>Prefers celadon.</p>'], [$note->string('type'), $note->string('title'), $note->string('description_html')]);
    }

    public function testProducts(): void
    {
        $desired = $this->desired('v1', Kind::Product, new ProductMapper(false));
        [$clay, $class] = array_map(Values::of(...), $desired);

        $variant = $clay->records('variants')[0];
        self::assertSame(['42.5', 'SF-CLAY-10', 'publish'], [$variant->string('regular_price'), $variant->nullableString('sku'), $clay->string('status')], 'the standard price and the stock keeping unit');
        self::assertSame(['ProductCode' => 'SF-CLAY', 'Family' => 'Supplies'], $clay->raw('product'));
        self::assertSame('private', $class->string('status'), 'an inactive product');
        self::assertSame('SF-CLASS', $class->records('variants')[0]->nullableString('sku'), 'the product code without a stock keeping unit');
        self::assertNotEmpty($desired[1]->warnings, 'no standard price');
    }

    public function testCustomersAndOrders(): void
    {
        [$ada] = array_map(Values::of(...), $this->desired('v1', Kind::Customer, new CustomerMapper()));
        [$order] = array_map(Values::of(...), $this->desired('v1', Kind::Order, new OrderMapper()));

        self::assertSame(['ada@fernhill.example', 'salesforce-003Fh00000Ada001AAA'], [$ada->nullableString('email'), $ada->string('username')]);
        self::assertSame('12 Cedar Lane', $ada->record('billing')?->string('address_1'));
        self::assertSame(['00000101', '2026-09-02T00:00:00Z', 'CAD', 'processing', '100'], [$order->string('name'), $order->string('created_at'), $order->string('currency'), $order->string('status'), $order->string('total')]);
        self::assertSame('gid://salesforce/Customer/003Fh00000Ada001AAA', $order->nullableString('customer_gid'));
        [$clay, $kit] = $order->records('line_items');
        self::assertSame(['gid://salesforce/Product2/01tFh00000Clay1AAA', 2, '85', 'Stoneware clay, 10 kg'], [$clay->nullableString('product_gid'), $clay->int('quantity'), $clay->string('total'), $clay->string('name')]);
        self::assertNull($kit->nullableString('product_gid'));
        self::assertSame(['AccountId' => '001Fh00000Fern01AAA', 'Status' => 'Activated', 'PoNumber' => 'PO-77'], $order->raw('order'), 'the status as Salesforce names it, with the other settable fields');
        $draft = Values::of($this->desired('v2', Kind::Order, new OrderMapper())[1]);
        self::assertSame('pending', $draft->string('status'), 'a draft order');
    }

    public function testBoards(): void
    {
        [$opportunities, $tasks] = array_map(Values::of(...), $this->desired('v1', Kind::CrmPipeline, new BoardMapper()));

        self::assertSame(['Salesforce opportunities', 'CAD'], [$opportunities->string('title'), $opportunities->nullableString('currency')]);
        self::assertSame([
            ['key' => 'Prospecting', 'title' => 'Prospecting', 'closed' => false, 'outcome' => null, 'probability' => '10'],
            ['key' => 'Negotiation/Review', 'title' => 'Negotiation', 'closed' => false, 'outcome' => null, 'probability' => '90'],
            ['key' => 'Closed Won', 'title' => 'Closed Won', 'closed' => true, 'outcome' => 'won', 'probability' => '100'],
            ['key' => 'Closed Lost', 'title' => 'Closed Lost', 'closed' => true, 'outcome' => 'lost', 'probability' => '0'],
        ], $opportunities->raw('stages'), 'the active stages in their order, keyed by API name');
        self::assertSame('Best Case', $opportunities->stringMap('forecast_categories')['Negotiation/Review']);
        self::assertSame(['Not Started', 'In Progress', 'Completed'], array_map(static fn(Values $stage): string => $stage->string('title'), $tasks->records('stages')));
        self::assertTrue($tasks->records('stages')[2]->bool('closed'));
    }

    public function testOpportunities(): void
    {
        [$mugs, $kiln] = array_map(Values::of(...), $this->desired('v1', Kind::CrmDeal, new OpportunityMapper()));

        self::assertSame(['gid://salesforce/Board/Opportunity', 'Negotiation/Review', 'open'], [$mugs->string('board_gid'), $mugs->string('stage_key'), $mugs->string('status')]);
        self::assertSame(['1500.50', 'CAD', '2026-10-15'], [$mugs->nullableString('lead_value'), $mugs->nullableString('currency'), $mugs->nullableString('due_at')]);
        self::assertSame(['gid://salesforce/Contact/003Fh00000Ada001AAA', 'gid://salesforce/Contact/003Fh00000Bo0001AAA'], $mugs->strings('contact_gids'), 'the primary contact first');
        self::assertSame(['gid://salesforce/Account/001Fh00000Fern01AAA'], $mugs->strings('company_gids'));
        self::assertSame(['id' => '005Fh00000Own01AAA', 'email' => 'e2e@example.com', 'name' => 'Eve Two'], $mugs->raw('owner'));
        self::assertSame(['Kiln_Count__c' => '2'], $mugs->raw('custom'));
        self::assertSame(['Type' => 'New Customer', 'LeadSource' => 'Web'], $mugs->raw('opportunity'));
        self::assertSame(['closed', 'lost', []], [$kiln->string('status'), $kiln->nullableString('outcome'), $kiln->strings('contact_gids')]);

        $won = Values::of($this->desired('v2', Kind::CrmDeal, new OpportunityMapper())[0]);
        self::assertSame(['Closed Won', 'won', '2026-10-15'], [$won->string('stage_key'), $won->nullableString('outcome'), $won->nullableString('completed_at')]);
    }

    public function testTasks(): void
    {
        $visit = Values::of($this->desired('v1', Kind::CrmTask, new TaskMapper())[0]);

        self::assertSame(['gid://salesforce/Board/Task', 'Not Started', 'open', 'high'], [$visit->string('board_gid'), $visit->string('stage_key'), $visit->string('status'), $visit->nullableString('priority')]);
        self::assertSame(['Visit the studio', '<p>Bring the glaze samples.</p>', '2026-10-30'], [$visit->string('title'), $visit->string('description_html'), $visit->nullableString('due_at')]);
        self::assertSame(['gid://salesforce/Contact/003Fh00000Ada001AAA'], $visit->strings('contact_gids'));
        self::assertSame(['TaskSubtype' => 'Task'], $visit->raw('task'));

        [$done, $lead] = array_map(Values::of(...), $this->desired('v2', Kind::CrmTask, new TaskMapper()));
        self::assertSame(['closed', '2026-10-20T18:00:00Z'], [$done->string('status'), $done->nullableString('completed_at')]);
        self::assertSame(['gid://salesforce/Lead/00QFh00000Cy0001AAA', 'low'], [$lead->strings('contact_gids')[0], $lead->nullableString('priority')], 'a task about a lead, at low priority');
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

    private function reader(string $state): SalesforceReader
    {
        return SalesforceReader::connect('https://fernhill.my.salesforce.test', BearerAuthorizer::token('t', 'Salesforce'), new ScriptedTransport(new FixtureResponder('salesforce', $state)), new RecordingSleeper());
    }
}
