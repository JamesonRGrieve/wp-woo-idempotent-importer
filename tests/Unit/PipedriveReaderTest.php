<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Pipedrive\ApiTokenAuthorizer;
use IdempotentImporter\Source\Pipedrive\PipedriveReader;
use IdempotentImporter\Source\RetryLater;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class PipedriveReaderTest extends TestCase
{
    private const API = 'https://fernhill.pipedrive.test';
    private const TOKEN = 'pd-api-token';

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
    }

    public function testPersonsArriveWithTheirFiltersFieldsAndMarketingStatusAskedFor(): void
    {
        $transport = self::transport('v1');

        $persons = $this->reader($transport)->page(Kind::Contact, null, null, '');

        self::assertSame(['gid://pipedrive/Person/201', 'gid://pipedrive/Person/202', 'gid://pipedrive/Person/203'], self::gids($persons->nodes));
        self::assertFalse($persons->hasNextPage);
        self::assertSame([81], $persons->nodes[0]->ints('filter_ids'), 'from the filter\'s persons');
        self::assertSame(['Favourite glaze', 'Lifetime spend', 'Last firing'], array_map(static fn(Node $field): string => $field->string('name'), $persons->nodes[0]->list('field_definitions')), 'the account\'s own person fields');
        self::assertSame(self::API . '/api/v2/persons?limit=100&include_fields=marketing_status', $transport->requests[0]['url']);
        self::assertSame(self::TOKEN, $transport->requests[0]['headers']['x-api-token']);
    }

    public function testIncrementalKindsAskForWhatChangedSince(): void
    {
        $transport = self::transport('v1');
        $reader = $this->reader($transport);

        $reader->page(Kind::CrmCompany, null, new DateTimeImmutable('2026-09-01T00:00:00Z'), '');

        self::assertStringContainsString('updated_since=2026-09-01T00%3A00%3A00%2B00%3A00', $transport->requests[0]['url']);
        foreach (Kind::SYNC_ORDER as $kind) {
            self::assertSame(in_array($kind, [Kind::Contact, Kind::CrmCompany, Kind::Product], true), $reader->isIncremental($kind), $kind->value);
        }
    }

    public function testListsArePagedByCursor(): void
    {
        $page = ['success' => true, 'data' => [], 'additional_data' => ['next_cursor' => 'eyJpZCI6MTAwfQ']];
        $transport = new ScriptedTransport(new FixtureResponder('pipedrive', 'v1'), [new HttpResponse(200, (string) json_encode($page))]);
        $reader = $this->reader($transport);

        $first = $reader->page(Kind::Product, null, null, '');
        $reader->page(Kind::Product, $first->endCursor, null, '');

        self::assertTrue($first->hasNextPage);
        self::assertSame('eyJpZCI6MTAwfQ', $first->endCursor);
        self::assertStringContainsString('cursor=eyJpZCI6MTAwfQ', $transport->requests[1]['url']);
    }

    public function testLabelsAndCategoriesAreFieldOptions(): void
    {
        $reader = $this->reader(self::transport('v1'));

        self::assertSame(['gid://pipedrive/Label/31', 'gid://pipedrive/Label/32'], self::gids($reader->page(Kind::CrmTag, null, null, '')->nodes));
        self::assertSame(['gid://pipedrive/Category/71', 'gid://pipedrive/Category/72'], self::gids($reader->page(Kind::Collection, null, null, '')->nodes));
        self::assertSame(['gid://pipedrive/Filter/81'], self::gids($reader->page(Kind::CrmList, null, null, '')->nodes));
    }

    public function testNotesThenDoneActivitiesOneNodePerPersonAndOrganization(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $notes = $reader->page(Kind::CrmNote, null, null, '');
        $activities = $reader->page(Kind::CrmNote, $notes->endCursor, null, '');

        self::assertSame(['gid://pipedrive/Note/401-person-201', 'gid://pipedrive/Note/401-org-101'], self::gids($notes->nodes));
        self::assertTrue($notes->hasNextPage, 'activities follow');
        self::assertSame(['gid://pipedrive/Activity/501-person-201', 'gid://pipedrive/Activity/501-person-202'], self::gids($activities->nodes), 'every participant');
        self::assertFalse($activities->hasNextPage);
    }

    public function testProductsArriveWithTheirVariationsAndTheDefaultCurrency(): void
    {
        $products = $this->reader(self::transport('v1'))->page(Kind::Product, null, null, '')->nodes;

        self::assertSame('CAD', $products[0]->string('currency'));
        self::assertSame([], $products[0]->list('variations'));
        self::assertCount(2, $products[1]->list('variations'));
    }

    public function testOrganizationsCarryTheirIndustrysName(): void
    {
        $organizations = $this->reader(self::transport('v1'))->page(Kind::CrmCompany, null, null, '')->nodes;

        self::assertSame('Retail', $organizations[0]->string('industry_label'));
        self::assertNull($organizations[1]->optionalString('industry_label'));
    }

    public function testReadsASingleObject(): void
    {
        $item = ['success' => true, 'data' => ['id' => 301, 'name' => 'Clay']];
        $reader = $this->reader(new ScriptedTransport(new FixtureResponder('pipedrive', 'v1'), [new HttpResponse(200, (string) json_encode($item))]));

        self::assertSame('gid://pipedrive/Product/301', $reader->node(Kind::Product, 'gid://pipedrive/Product/301')?->id());
        $this->expectException(SourceError::class);
        $reader->node(Kind::CrmNote, 'gid://pipedrive/Note/1');
    }

    public function testPacesTheBurstAndPausesOnASpentDailyBudget(): void
    {
        $this->reader(self::transport('v1'))->page(Kind::CrmTag, null, null, '');
        $this->reader(self::transport('v1'))->page(Kind::Product, null, null, '');
        self::assertNotEmpty($this->sleeper->waits);
        self::assertEqualsWithDelta(0.1, $this->sleeper->waits[0], 0.001, 'ten requests a second');

        $this->expectException(RetryLater::class);
        $this->reader(new ScriptedTransport(null, array_fill(0, 6, new HttpResponse(429, '{}'))))->page(Kind::CrmTag, null, null, '');
    }

    public function testARejectedTokenStopsTheRun(): void
    {
        $this->expectException(CredentialsRejected::class);
        $this->reader(new ScriptedTransport(null, [new HttpResponse(401, '{}')]))->page(Kind::CrmTag, null, null, '');
    }

    public function testNoStoreSettings(): void
    {
        $this->expectException(SourceError::class);
        $this->reader(self::transport('v1'))->shop();
    }

    private function reader(ScriptedTransport $transport): PipedriveReader
    {
        return PipedriveReader::connect(self::API, new ApiTokenAuthorizer(self::TOKEN), $transport, $this->sleeper);
    }

    private static function transport(string $state): ScriptedTransport
    {
        return new ScriptedTransport(new FixtureResponder('pipedrive', $state));
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<string>
     */
    private static function gids(array $nodes): array
    {
        return array_map(static fn(Node $node): string => $node->id(), $nodes);
    }
}
