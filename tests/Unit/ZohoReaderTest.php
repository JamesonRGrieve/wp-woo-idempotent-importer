<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\Zoho\Mapping\Fields;
use IdempotentImporter\Source\Zoho\ZohoAdapter;
use IdempotentImporter\Source\Zoho\ZohoReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class ZohoReaderTest extends TestCase
{
    private const DOMAIN = 'https://www.zohoapis.test';
    private const TOKEN = '1000.zoho-token';

    public function testRecordsAreAskedForEveryFieldWithZohosAuthorizationScheme(): void
    {
        $transport = self::transport('v1');

        $accounts = $this->reader($transport)->page(Kind::CrmCompany, null, null, '');

        self::assertSame(['gid://zoho/Account/5725767000011373001', 'gid://zoho/Account/5725767000011373002'], self::gids($accounts->nodes));
        self::assertFalse($accounts->hasNextPage);
        self::assertSame(self::DOMAIN . '/crm/v8/settings/fields?module=Accounts', $transport->requests[0]['url']);
        $query = self::query($transport->requests[1]['url']);
        self::assertSame(['fields', 'per_page', 'page'], array_keys($query));
        self::assertStringStartsWith('id,Account_Name,Phone', $query['fields']);
        self::assertSame(ZohoAdapter::SCHEME . ' ' . self::TOKEN, $transport->requests[0]['headers']['Authorization']);
        self::assertSame(['Wholesale_Tier'], array_map(static fn(Node $field): string => $field->string('api_name'), $accounts->nodes[0]->list('custom_fields')));
        self::assertNotContains('Created_Time', $accounts->nodes[0]->strings('stored_fields'), 'read-only fields are not settable');
    }

    public function testAModuleWithMoreThanFiftyFieldsIsReadFiftyAtATime(): void
    {
        $fields = ['fields' => array_map(static fn(int $n): array => ['api_name' => "Field_{$n}", 'field_label' => "Field {$n}", 'data_type' => 'text', 'custom_field' => false, 'read_only' => false, 'field_read_only' => false], range(1, 60))];
        $first = ['data' => [['id' => '1', 'Field_1' => 'a'], ['id' => '2', 'Field_1' => 'b']], 'info' => ['more_records' => false, 'page' => 1]];
        $rest = ['data' => [['id' => '1', 'Field_60' => 'z'], ['id' => '2', 'Field_60' => 'y']], 'info' => ['more_records' => false]];
        $transport = new ScriptedTransport(null, [new HttpResponse(200, (string) json_encode($fields)), new HttpResponse(200, (string) json_encode($first)), new HttpResponse(200, (string) json_encode($rest))]);

        $nodes = $this->reader($transport)->page(Kind::CrmList, null, null, '')->nodes;

        self::assertCount(3, $transport->requests);
        self::assertCount(50, explode(',', self::query($transport->requests[1]['url'])['fields']), 'the id and 49 fields');
        self::assertSame('1,2', self::query($transport->requests[2]['url'])['ids'], 'the same records, by id');
        self::assertSame(['a', 'z'], [$nodes[0]->string('Field_1'), $nodes[0]->string('Field_60')]);
    }

    public function testPagesBeyondTheFirstUseTheNextPageToken(): void
    {
        $fields = ['fields' => [['api_name' => 'Campaign_Name', 'field_label' => 'Name', 'data_type' => 'text', 'custom_field' => false, 'read_only' => false, 'field_read_only' => false]]];
        $page = ['data' => [['id' => '1']], 'info' => ['more_records' => true, 'page' => 1, 'next_page_token' => 'c8582e7f']];
        $transport = new ScriptedTransport(null, [new HttpResponse(200, (string) json_encode($fields)), new HttpResponse(200, (string) json_encode($page)), new HttpResponse(200, (string) json_encode(['data' => [], 'info' => ['more_records' => false]]))]);
        $reader = $this->reader($transport);

        $first = $reader->page(Kind::CrmList, null, null, '');
        $reader->page(Kind::CrmList, $first->endCursor, null, '');

        self::assertSame('token:c8582e7f', $first->endCursor);
        self::assertStringContainsString('page_token=c8582e7f', $transport->requests[2]['url']);
    }

    public function testContactsThenUnconvertedLeadsWithTheirCampaigns(): void
    {
        $reader = $this->reader(self::transport('v2'));

        $contacts = $reader->page(Kind::Contact, null, null, '');
        $leads = $reader->page(Kind::Contact, $contacts->endCursor, null, '');

        self::assertSame('1|', $contacts->endCursor);
        self::assertSame(['5725767000011360001'], $contacts->nodes[0]->strings('campaign_ids'));
        self::assertSame(['gid://zoho/Lead/5725767000011357001'], self::gids($leads->nodes));
        self::assertSame(['5725767000011360001'], $leads->nodes[0]->strings('campaign_ids'));
        self::assertSame('Leads', $leads->nodes[0]->string('module'));
    }

    public function testNotesCallsAndEventsBelongToTheirContactLeadOrAccount(): void
    {
        $reader = $this->reader(self::transport('v2'));
        $nodes = [];
        $cursor = null;
        do {
            $page = $reader->page(Kind::CrmNote, $cursor, null, '');
            $nodes = [...$nodes, ...$page->nodes];
            $cursor = $page->endCursor;
        } while ($page->hasNextPage);

        self::assertSame(
            [['Contact', '5725767000011356001'], ['Lead', '5725767000011357001'], ['Contact', '5725767000011356001'], ['Account', '5725767000011373002']],
            array_map(static fn(Node $node): array => [$node->string('belongs_to_type'), $node->string('belongs_to_id')], $nodes),
        );
    }

    public function testTagsCategoriesOrdersAndCustomers(): void
    {
        $reader = $this->reader(self::transport('v2'));

        self::assertSame(['gid://zoho/Tag/5725767000011390001'], self::gids($reader->page(Kind::CrmTag, null, null, '')->nodes));
        self::assertSame([Fields::categoryId('Clay'), Fields::categoryId('Glaze Kits')], self::gids($reader->page(Kind::Collection, null, null, '')->nodes));
        $orders = $reader->page(Kind::Order, null, null, '')->nodes;
        self::assertCount(2, $orders[0]->list('Ordered_Items'), 'items from the single record');
        self::assertSame('CAD', $orders[0]->string('org_currency'));
        self::assertSame(['gid://zoho/Customer/5725767000011356001', 'gid://zoho/Customer/5725767000011356002'], self::gids($reader->page(Kind::Customer, null, null, '')->nodes));
    }

    public function testRejectedTokensStopTheRun(): void
    {
        $this->expectException(CredentialsRejected::class);
        $this->reader(new ScriptedTransport(null, [new HttpResponse(401, '{"code":"INVALID_TOKEN"}')]))->page(Kind::CrmCompany, null, null, '');
    }

    public function testNothingIncrementalNoStoreSettingsAndAnApiDomainIsRequired(): void
    {
        $reader = $this->reader(self::transport('v1'));
        foreach (Kind::SYNC_ORDER as $kind) {
            self::assertFalse($reader->isIncremental($kind), $kind->value);
        }
        try {
            $reader->shop();
            self::fail('no store settings');
        } catch (SourceError) {
            $this->expectException(SourceError::class);
            ZohoReader::connect('', BearerAuthorizer::token(self::TOKEN, 'Zoho'), self::transport('v1'), new RecordingSleeper());
        }
    }

    private function reader(ScriptedTransport $transport): ZohoReader
    {
        return ZohoReader::connect(self::DOMAIN, BearerAuthorizer::token(self::TOKEN, 'Zoho CRM', [], ZohoAdapter::SCHEME), $transport, new RecordingSleeper());
    }

    /** @return array<string, string> a request's query parameters, decoded, in order */
    private static function query(string $url): array
    {
        $query = [];
        foreach (explode('&', (string) parse_url($url, PHP_URL_QUERY)) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[rawurldecode($name)] = rawurldecode($value);
        }

        return $query;
    }

    private static function transport(string $state): ScriptedTransport
    {
        return new ScriptedTransport(new FixtureResponder('zoho', $state));
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
