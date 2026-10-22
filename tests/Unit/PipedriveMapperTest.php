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
use IdempotentImporter\Source\Pipedrive\ApiTokenAuthorizer;
use IdempotentImporter\Source\Pipedrive\Mapping\ActivityMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\BoardMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\CategoryMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\DealMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\FilterMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\LabelMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\OrganizationMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\PersonMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\ProductMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\TaskMapper;
use IdempotentImporter\Source\Pipedrive\PipedriveReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class PipedriveMapperTest extends TestCase
{
    public function testPersons(): void
    {
        $nodes = $this->reader('v1')->page(Kind::Contact, null, null, '')->nodes;
        $ada = Values::of((new PersonMapper())->map($nodes[0]));

        self::assertSame(['ada.thorne@pipedrive.example', 'Ada', 'Thorne', '+1 250 555 0111'], [$ada->string('email'), $ada->nullableString('first_name'), $ada->nullableString('last_name'), $ada->nullableString('phone')], 'the primary email and phone');
        self::assertSame(['address_line_1' => '12 Cedar Lane', 'city' => 'Victoria', 'state' => 'BC', 'postal_code' => 'V8V 1A1', 'country' => 'Canada'], $ada->record('address')?->all());
        self::assertSame(['transactional', 'lead', 'Pipedrive'], [$ada->string('status'), $ada->string('contact_type'), $ada->string('source')], 'no marketing status: transactional');
        self::assertSame(['gid://pipedrive/Organization/101'], $ada->strings('company_gids'));
        self::assertSame(['gid://pipedrive/Label/31', 'gid://pipedrive/Label/32'], $ada->strings('tag_gids'));
        self::assertSame(['gid://pipedrive/Filter/81'], $ada->strings('list_gids'));
        $fields = $ada->records('custom_fields');
        self::assertSame(['pipedrive_8c2d9f1a0b3e4f5a6b7c8d9e0f1a2b3c4d5e6f70', 'Favourite glaze', 'select-one', 'Celadon'], [$fields[0]->string('key'), $fields[0]->string('label'), $fields[0]->string('type'), $fields[0]->raw('value')], 'a choice as its label');
        self::assertSame(['text', '1200.5 CAD'], [$fields[1]->string('type'), $fields[1]->raw('value')], 'money with its currency');
        self::assertSame(['date', '2026-08-20'], [$fields[2]->string('type'), $fields[2]->raw('value')]);
        self::assertSame('ada@studio.example', $ada->record('person')?->records('emails')[0]->string('value'), 'other emails are meta');
    }

    public function testMarketingStatusIsTheContactsStatus(): void
    {
        $base = $this->reader('v1')->page(Kind::Contact, null, null, '')->nodes[0]->raw();
        $status = static fn(string $marketing): string => Values::of((new PersonMapper())->map(new Node([...$base, 'marketing_status' => $marketing])))->string('status');

        self::assertSame(['subscribed', 'unsubscribed', 'unsubscribed', 'transactional'], [$status('subscribed'), $status('unsubscribed'), $status('archived'), $status('no_consent')]);
    }

    public function testAPersonWithoutAnEmailIsNotMirrored(): void
    {
        $this->expectException(Unsupported::class);
        (new PersonMapper())->map($this->reader('v1')->page(Kind::Contact, null, null, '')->nodes[2]);
    }

    public function testOrganizationsLabelsFiltersAndCategories(): void
    {
        [$fernhill] = array_map(Values::of(...), $this->desired('v1', Kind::CrmCompany, new OrganizationMapper()));
        [$wholesale] = array_map(Values::of(...), $this->desired('v1', Kind::CrmTag, new LabelMapper()));
        [$lovers] = array_map(Values::of(...), $this->desired('v1', Kind::CrmList, new FilterMapper()));
        [$clay] = array_map(Values::of(...), $this->desired('v1', Kind::Collection, new CategoryMapper()));

        self::assertSame(['Fernhill Ceramics', 'https://fernhill.example', 'Retail', 4], [$fernhill->string('name'), $fernhill->nullableString('website'), $fernhill->nullableString('industry'), $fernhill->nullableInt('employees_number')]);
        self::assertSame(['address_line_1' => '88 Kiln Road', 'city' => 'Victoria', 'state' => 'BC', 'postal_code' => 'V8V 2B2', 'country' => 'Canada'], $fernhill->record('address')?->all());
        self::assertSame('Gold', $fernhill->records('custom_fields')[0]->raw('value'));
        self::assertSame(['Wholesale', 'green'], [$wholesale->string('title'), $wholesale->nullableString('color')]);
        self::assertSame('Glaze lovers', $lovers->string('title'));
        self::assertSame('Clay', $clay->string('name'));
    }

    public function testNotesAndActivities(): void
    {
        $reader = $this->reader('v1');
        $notes = $reader->page(Kind::CrmNote, null, null, '');
        $activities = $reader->page(Kind::CrmNote, $notes->endCursor, null, '');
        [$personNote, $orgNote] = array_map(static fn(Node $node): Values => Values::of((new ActivityMapper())->map($node)), $notes->nodes);
        [$call, $participant] = array_map(static fn(Node $node): Values => Values::of((new ActivityMapper())->map($node)), $activities->nodes);

        self::assertSame(['note', '<p>Asked about wholesale.</p>', '2026-08-15T17:00:00Z', 'gid://pipedrive/Person/201'], [$personNote->string('type'), $personNote->string('description_html'), $personNote->string('created_at'), $personNote->nullableString('contact_gid')]);
        self::assertSame('gid://pipedrive/Organization/101', $orgNote->nullableString('company_gid'));
        self::assertSame(['call', 'Wholesale follow-up', '2026-08-16T17:15:00Z'], [$call->string('type'), $call->string('title'), $call->string('created_at')], 'dated when it was marked done');
        self::assertSame('<p>Discussed pricing.</p><dl><dt>Type</dt><dd>call</dd><dt>Due</dt><dd>2026-08-16 17:00</dd><dt>Duration</dt><dd>00:15</dd></dl>', $call->string('description_html'));
        self::assertSame('gid://pipedrive/Person/202', $participant->nullableString('contact_gid'), 'also on its other participant');
        self::assertSame(9, $call->nullableInt('owner_id'));
    }

    public function testProducts(): void
    {
        $desired = $this->desired('v1', Kind::Product, new ProductMapper(false));
        [$clay, $class] = array_map(Values::of(...), $desired);

        self::assertTrue($clay->bool('simple'));
        $variant = $clay->records('variants')[0];
        self::assertSame(['42.5', '18.25', 'PD-CLAY'], [$variant->string('regular_price'), $variant->nullableString('cost'), $variant->nullableString('sku')], 'the price in the default currency');
        self::assertSame(['gid://pipedrive/Category/71'], $clay->strings('collections'));
        self::assertCount(2, $clay->record('product')?->records('prices') ?? [], 'every currency\'s price is meta');
        self::assertFalse($class->bool('simple'));
        [$evenings, $weekends] = $class->records('variants');
        self::assertSame(['gid://pipedrive/Variation/3021', ['Variation' => 'Evenings'], '160'], [$evenings->string('gid'), $evenings->stringMap('options'), $evenings->string('regular_price')]);
        self::assertSame('180', $weekends->string('regular_price'), "a variation's own price");
        self::assertNotEmpty($desired[1]->warnings, 'billed monthly');
    }

    public function testBoards(): void
    {
        [$wholesale, $activities] = array_map(Values::of(...), $this->desired('v1', Kind::CrmPipeline, new BoardMapper()));

        self::assertSame('Wholesale', $wholesale->string('title'));
        self::assertSame([
            ['key' => '11', 'title' => 'Qualified', 'closed' => false, 'outcome' => null, 'probability' => '20'],
            ['key' => '12', 'title' => 'Proposal made', 'closed' => false, 'outcome' => null, 'probability' => '60'],
            ['key' => '13', 'title' => 'Negotiations', 'closed' => false, 'outcome' => null, 'probability' => '90'],
        ], $wholesale->raw('stages'), 'in order; won and lost are a deal\'s status, not stages');
        self::assertSame(['todo', 'done'], array_map(static fn(Values $stage): string => $stage->string('key'), $activities->records('stages')));
        self::assertCount(2, Values::of($this->desired('v2', Kind::CrmPipeline, new BoardMapper())[0])->records('stages'), 'a deleted stage leaves the board');
    }

    public function testDeals(): void
    {
        [$mugs, $kiln] = array_map(Values::of(...), $this->desired('v1', Kind::CrmDeal, new DealMapper()));

        self::assertSame(['gid://pipedrive/Pipeline/1', '13', 'open', '1500.50', 'CAD'], [$mugs->string('board_gid'), $mugs->string('stage_key'), $mugs->string('status'), $mugs->nullableString('lead_value'), $mugs->nullableString('currency')]);
        self::assertSame(['gid://pipedrive/Person/201'], $mugs->strings('contact_gids'));
        self::assertSame(['gid://pipedrive/Organization/101'], $mugs->strings('company_gids'));
        self::assertSame(['id' => '9', 'email' => 'e2e@example.com', 'name' => 'Isla Fenn'], $mugs->raw('owner'));
        self::assertSame('2026-10-15', $mugs->nullableString('due_at'), 'the expected close date, a date');
        self::assertSame(['is_archived' => false, 'visible_to' => 3, 'origin' => 'ManuallyCreated'], $mugs->raw('deal'));
        self::assertSame(['closed', 'lost', '2026-09-20T16:00:00Z'], [$kiln->string('status'), $kiln->nullableString('outcome'), $kiln->nullableString('completed_at')]);
        self::assertSame('Went with a local supplier', $kiln->record('deal')?->string('lost_reason'));

        $won = Values::of($this->desired('v2', Kind::CrmDeal, new DealMapper())[0]);
        self::assertSame(['12', 'closed', 'won', '2026-10-20T16:00:00Z'], [$won->string('stage_key'), $won->string('status'), $won->nullableString('outcome'), $won->nullableString('completed_at')]);
    }

    public function testActivitiesAreTasks(): void
    {
        $tasks = array_map(static fn(Node $node): Values => Values::of((new TaskMapper())->map($node) ?? throw new \LogicException('no task')), $this->every('v1', Kind::CrmTask));
        [$visit, $call] = $tasks;

        self::assertSame(['gid://pipedrive/ActivityBoard/activities', 'todo', 'open'], [$visit->string('board_gid'), $visit->string('stage_key'), $visit->string('status')]);
        self::assertSame(['Visit the studio', '2026-10-30T17:00:00Z'], [$visit->string('title'), $visit->nullableString('due_at')], 'its due date and time (UTC)');
        self::assertStringStartsWith('<p>Bring the glaze samples.</p><dl><dt>Type</dt><dd>task</dd>', $visit->string('description_html'));
        self::assertSame(['type' => 'task', 'deal_id' => 401, 'duration' => '00:30', 'busy' => false], $visit->raw('activity'));
        self::assertSame(['done', 'closed', '2026-08-16T17:15:00Z'], [$call->string('stage_key'), $call->string('status'), $call->nullableString('completed_at')], 'a done activity is a done task');
        self::assertSame(['gid://pipedrive/Person/201', 'gid://pipedrive/Person/202'], $call->strings('contact_gids'), 'its person first, then its participants');
    }

    /**
     * Every node of a kind, following the reader's pages.
     *
     * @return list<Node>
     */
    private function every(string $state, Kind $kind): array
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

    private function reader(string $state): PipedriveReader
    {
        return PipedriveReader::connect('https://fernhill.pipedrive.test', new ApiTokenAuthorizer('t'), new ScriptedTransport(new FixtureResponder('pipedrive', $state)), new RecordingSleeper());
    }
}
