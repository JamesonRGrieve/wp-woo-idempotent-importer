<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\HubSpot\HubSpotReader;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\RetryLater;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class HubSpotReaderTest extends TestCase
{
    private const API = 'https://api.hubapi.test';
    private const TOKEN = 'pat-na1-fixture';

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
    }

    public function testContactsAskForEveryPropertyAndArriveWithTheirCompaniesListsAndCustomFields(): void
    {
        $transport = self::transport('v1');

        $contacts = $this->reader($transport)->page(Kind::Contact, null, null, '');

        self::assertSame(['gid://hubspot/Contact/501', 'gid://hubspot/Contact/502', 'gid://hubspot/Contact/504'], self::gids($contacts->nodes));
        self::assertFalse($contacts->hasNextPage);
        $ada = $contacts->nodes[0];
        self::assertSame(['41', '42'], $ada->strings('list_ids'), 'from every contact list\'s memberships');
        self::assertSame(['favorite_glaze', 'kiln_sizes', 'member_since', 'last_class_at', 'studio_notes'], array_map(static fn(Node $definition): string => $definition->string('name'), $ada->list('custom_properties')), "the account's own, visible properties");
        self::assertNotContains('hs_analytics_num_visits', $ada->strings('stored_properties'), 'a calculated property changes with every visit');
        self::assertNotContains('lastmodifieddate', $ada->strings('stored_properties'), 'a read-only one too');
        $urls = array_column($transport->requests, 'url');
        $list = array_values(array_filter($urls, static fn(string $url): bool => str_starts_with($url, self::API . '/crm/v3/objects/contacts?')))[0];
        parse_str((string) parse_url($list, PHP_URL_QUERY), $query);
        self::assertSame('companies', $query['associations']);
        self::assertSame('100', $query['limit']);
        self::assertSame('false', $query['archived']);
        $properties = $query['properties'] ?? null;
        self::assertIsString($properties);
        self::assertContains('hs_analytics_num_visits', explode(',', $properties), 'every property is asked for');
        self::assertSame('Bearer ' . self::TOKEN, $transport->requests[0]['headers']['Authorization']);
    }

    public function testListsAreContactListsSearchedByOffset(): void
    {
        $transport = self::transport('v1');

        $lists = $this->reader($transport)->page(Kind::CrmList, null, null, '');

        self::assertSame(['gid://hubspot/List/41', 'gid://hubspot/List/42'], self::gids($lists->nodes));
        self::assertFalse($lists->hasNextPage);
        self::assertSame(['offset' => 0, 'count' => 100, 'objectTypeId' => '0-1', 'additionalProperties' => []], json_decode($transport->requests[0]['body'], true));
    }

    public function testObjectsArePagedByTheAfterCursor(): void
    {
        $first = ['results' => [['id' => '9001', 'properties' => ['name' => 'A'], 'createdAt' => '2026-08-01T16:00:00Z', 'updatedAt' => '2026-08-01T16:00:00Z', 'archived' => false]], 'paging' => ['next' => ['after' => 'NTI1Cg']]];
        $transport = new ScriptedTransport(new FixtureResponder('hubspot', 'v1'), []);
        $reader = $this->reader($transport);
        $reader->page(Kind::CrmCompany, null, null, '');
        $transport = new ScriptedTransport(new FixtureResponder('hubspot', 'v1'), [new HttpResponse(200, (string) json_encode(['results' => []])), new HttpResponse(200, (string) json_encode($first))]);
        $reader = $this->reader($transport);

        $page = $reader->page(Kind::CrmCompany, null, null, '');
        $next = $reader->page(Kind::CrmCompany, $page->endCursor, null, '');

        self::assertTrue($page->hasNextPage);
        self::assertSame('NTI1Cg', $page->endCursor);
        self::assertStringContainsString('after=NTI1Cg', $transport->requests[2]['url']);
        self::assertSame(['gid://hubspot/Company/9001', 'gid://hubspot/Company/9002'], self::gids($next->nodes));
    }

    public function testActivitiesAreReadTypeByTypeOneNodePerContactAndCompany(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $nodes = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $reader->page(Kind::CrmNote, $cursor, null, '');
            $nodes = [...$nodes, ...$page->nodes];
            $cursor = $page->endCursor;
            ++$pages;
        } while ($page->hasNextPage);

        self::assertSame(4, $pages, 'notes, calls, emails, meetings');
        self::assertSame([
            'gid://hubspot/Note/8101-contact-501',
            'gid://hubspot/Note/8101-company-9001',
            'gid://hubspot/Call/8201-contact-501',
            'gid://hubspot/Email/8301-contact-501',
            'gid://hubspot/Email/8301-contact-502',
            'gid://hubspot/Meeting/8401-company-9002',
        ], self::gids($nodes));
        self::assertSame(['notes', '501'], [$nodes[0]->string('engagement_type'), $nodes[0]->string('contact_id')]);
        self::assertSame('9001', $nodes[1]->string('company_id'));
    }

    public function testOrdersArriveWithTheirLineItemsAndCustomersAreTheirContacts(): void
    {
        $reader = $this->reader(self::transport('v2'));

        $orders = $reader->page(Kind::Order, null, null, '')->nodes;
        $customers = $reader->page(Kind::Customer, null, null, '')->nodes;

        self::assertSame(['gid://hubspot/Order/6001', 'gid://hubspot/Order/6002'], self::gids($orders));
        self::assertSame(['gid://hubspot/LineItem/6101', 'gid://hubspot/LineItem/6102'], self::gids($orders[0]->list('line_items')), 'in the order the order names them');
        self::assertSame(['gid://hubspot/Customer/501', 'gid://hubspot/Customer/503'], self::gids($customers));
    }

    public function testReadsASingleObject(): void
    {
        $reader = $this->reader(self::transport('v1'));

        self::assertSame(['41', '42'], $reader->node(Kind::Contact, 'gid://hubspot/Contact/501')?->strings('list_ids'));
        self::assertNull($reader->node(Kind::Contact, 'gid://hubspot/Contact/999'));
        $this->expectException(SourceError::class);
        $reader->node(Kind::CrmNote, 'gid://hubspot/Note/8101-contact-501');
    }

    public function testHubSpotHasNoStoreSettingsAndNothingIsIncremental(): void
    {
        $reader = $this->reader(self::transport('v1'));

        foreach (Kind::SYNC_ORDER as $kind) {
            self::assertFalse($reader->isIncremental($kind), $kind->value);
        }
        $this->expectException(SourceError::class);
        $reader->shop();
    }

    public function testPacesTenRequestsASecondAndPausesWhenTheWindowIsSpent(): void
    {
        $spent = new HttpResponse(200, (string) json_encode(['results' => []]), headers: ['x-hubspot-ratelimit-remaining' => '1']);
        $reader = $this->reader(new ScriptedTransport(new FixtureResponder('hubspot', 'v1'), [$spent]));

        $reader->page(Kind::CrmCompany, null, null, '');
        self::assertSame([10.0], $this->sleeper->waits, 'after the last request of the window the next waits it out (short enough to wait inside a request)');

        $this->sleeper = new RecordingSleeper();
        $this->reader(self::transport('v1'))->page(Kind::Product, null, null, '');
        self::assertCount(1, $this->sleeper->waits);
        self::assertEqualsWithDelta(0.1, $this->sleeper->waits[0], 0.001, 'a tenth of a second apart');
    }

    public function testARejectedTokenStopsTheRun(): void
    {
        $this->expectException(CredentialsRejected::class);
        $this->reader(new ScriptedTransport(null, [new HttpResponse(401, '{}')]))->page(Kind::CrmCompany, null, null, '');
    }

    public function testAPersistent429PausesTheRun(): void
    {
        $throttled = array_fill(0, 6, new HttpResponse(429, '{"errorType":"RATE_LIMIT"}'));
        $this->expectException(RetryLater::class);
        $this->reader(new ScriptedTransport(null, $throttled))->page(Kind::CrmCompany, null, null, '');
    }

    private function reader(ScriptedTransport $transport): HubSpotReader
    {
        return HubSpotReader::connect(self::API, BearerAuthorizer::token(self::TOKEN, 'HubSpot'), $transport, $this->sleeper);
    }

    private static function transport(string $state): ScriptedTransport
    {
        return new ScriptedTransport(new FixtureResponder('hubspot', $state));
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
