<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\RetryLater;
use IdempotentImporter\Source\Salesforce\SalesforceReader;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class SalesforceReaderTest extends TestCase
{
    private const INSTANCE = 'https://fernhill.my.salesforce.test';
    private const API = self::INSTANCE . '/services/data/v62.0';
    private const TOKEN = '00DFh0000-session';

    public function testRecordsAreQueriedWithEveryDescribedFieldAndTheTokenAsBearer(): void
    {
        $transport = self::transport('v1');

        $accounts = $this->reader($transport)->page(Kind::CrmCompany, null, null, '');

        self::assertSame(['gid://salesforce/Account/001Fh00000Fern01AAA', 'gid://salesforce/Account/001Fh00000Kiln01AAA'], self::gids($accounts->nodes));
        self::assertFalse($accounts->hasNextPage);
        self::assertSame(self::API . '/sobjects/Account/describe', $transport->requests[0]['url']);
        parse_str((string) parse_url($transport->requests[1]['url'], PHP_URL_QUERY), $query);
        $soql = $query['q'] ?? null;
        self::assertIsString($soql);
        self::assertStringStartsWith('SELECT Id, IsDeleted, CreatedDate', $soql);
        self::assertStringNotContainsString('BillingAddress', $soql, 'a compound address is read through its parts');
        self::assertStringEndsWith(' FROM Account ORDER BY Id', $soql);
        self::assertSame('Bearer ' . self::TOKEN, $transport->requests[0]['headers']['Authorization']);
        $fernhill = $accounts->nodes[0];
        self::assertSame(['Wholesale_Tier__c'], array_map(static fn(Node $field): string => $field->string('name'), $fernhill->list('custom_fields')));
        self::assertNotContains('SystemModstamp', $fernhill->strings('stored_fields'), 'system fields are not settable');
    }

    public function testQueriesArePagedByTheirNextRecordsUrl(): void
    {
        $first = ['totalSize' => 2, 'done' => false, 'nextRecordsUrl' => '/services/data/v62.0/query/01gFh00000Abc-2000', 'records' => []];
        $transport = new ScriptedTransport(new FixtureResponder('salesforce', 'v1'), []);
        $reader = $this->reader($transport);
        $reader->page(Kind::CrmTag, null, null, '');
        $transport = new ScriptedTransport(new FixtureResponder('salesforce', 'v1'), [
            new HttpResponse(200, (string) json_encode(['name' => 'Topic', 'fields' => []])),
            new HttpResponse(200, (string) json_encode($first)),
            new HttpResponse(200, (string) json_encode(['totalSize' => 2, 'done' => true, 'records' => []])),
        ]);
        $reader = $this->reader($transport);

        $page = $reader->page(Kind::CrmTag, null, null, '');
        $next = $reader->page(Kind::CrmTag, $page->endCursor, null, '');

        self::assertTrue($page->hasNextPage);
        self::assertSame('/services/data/v62.0/query/01gFh00000Abc-2000', $page->endCursor);
        self::assertSame(self::INSTANCE . '/services/data/v62.0/query/01gFh00000Abc-2000', $transport->requests[2]['url']);
        self::assertFalse($next->hasNextPage);
    }

    public function testContactsThenUnconvertedLeadsWithTheirCampaignsAndTopics(): void
    {
        $reader = $this->reader(self::transport('v2'));

        $contacts = $reader->page(Kind::Contact, null, null, '');
        $leads = $reader->page(Kind::Contact, $contacts->endCursor, null, '');

        self::assertTrue($contacts->hasNextPage, 'leads follow');
        self::assertSame('1|', $contacts->endCursor);
        self::assertSame(['gid://salesforce/Contact/003Fh00000Ada001AAA', 'gid://salesforce/Contact/003Fh00000Bo0001AAA', 'gid://salesforce/Contact/003Fh00000NoMl01AAA'], self::gids($contacts->nodes));
        self::assertSame(['701Fh00000Mem001AAA', '701Fh00000Evt001AAA'], $contacts->nodes[0]->strings('campaign_ids'));
        self::assertSame(['0TOFh00000Whl001AAA'], $contacts->nodes[0]->strings('topic_ids'));
        self::assertSame(['gid://salesforce/Lead/00QFh00000Cy0001AAA'], self::gids($leads->nodes));
        self::assertSame(['701Fh00000Evt001AAA'], $leads->nodes[0]->strings('campaign_ids'));
        self::assertFalse($leads->hasNextPage);
    }

    public function testActivitiesBelongToTheirContactLeadOrAccount(): void
    {
        $reader = $this->reader(self::transport('v2'));
        $nodes = [];
        $cursor = null;
        do {
            $page = $reader->page(Kind::CrmNote, $cursor, null, '');
            $nodes = [...$nodes, ...$page->nodes];
            $cursor = $page->endCursor;
        } while ($page->hasNextPage);

        self::assertSame([
            'gid://salesforce/Task/00TFh00000Call1AAA',
            'gid://salesforce/Task/00TFh00000Mail1AAA',
            'gid://salesforce/Event/00UFh00000Evnt1AAA',
            'gid://salesforce/Note/002Fh00000Note1AAA',
            'gid://salesforce/Note/002Fh00000Note2AAA',
        ], self::gids($nodes));
        self::assertSame(['Contact', '003Fh00000Ada001AAA'], [$nodes[0]->string('owner_type'), $nodes[0]->string('owner_id')], 'the person before the account it relates to');
        self::assertSame(['Account', '001Fh00000Kiln01AAA'], [$nodes[2]->string('owner_type'), $nodes[2]->string('owner_id')], 'an event on an account only');
        self::assertSame('Lead', $nodes[4]->string('owner_type'));
    }

    public function testProductsOrdersAndCustomers(): void
    {
        $reader = $this->reader(self::transport('v2'));

        $products = $reader->page(Kind::Product, null, null, '')->nodes;
        $orders = $reader->page(Kind::Order, null, null, '')->nodes;
        $customers = $reader->page(Kind::Customer, null, null, '')->nodes;

        self::assertSame(['gid://salesforce/Product2/01tFh00000Clay1AAA', 'gid://salesforce/Product2/01tFh00000Clss1AAA'], self::gids($products));
        self::assertSame(['gid://salesforce/Order/801Fh00000Ord01AAA', 'gid://salesforce/Order/801Fh00000Ord02AAA'], self::gids($orders));
        self::assertSame('CAD', $orders[0]->string('org_currency'), "the org's default currency");
        self::assertSame(['gid://salesforce/Customer/003Fh00000Ada001AAA', 'gid://salesforce/Customer/003Fh00000Bo0001AAA'], self::gids($customers), 'the contacts orders are billed to');
    }

    public function testASpentDailyAllowancePausesTheRunInsteadOfHoldingTheCredentials(): void
    {
        $limit = new HttpResponse(403, '[{"message":"TotalRequests Limit exceeded.","errorCode":"REQUEST_LIMIT_EXCEEDED"}]');
        $reader = $this->reader(new ScriptedTransport(null, array_fill(0, 6, $limit)));

        $this->expectException(RetryLater::class);
        $reader->page(Kind::CrmCompany, null, null, '');
    }

    public function testARejectedSessionStopsTheRun(): void
    {
        $reader = $this->reader(new ScriptedTransport(null, [new HttpResponse(401, '[{"errorCode":"INVALID_SESSION_ID"}]')]));

        $this->expectException(CredentialsRejected::class);
        $reader->page(Kind::CrmCompany, null, null, '');
    }

    public function testNoStoreSettingsNoSingleReadsAndNothingIncremental(): void
    {
        $reader = $this->reader(self::transport('v1'));

        foreach (Kind::SYNC_ORDER as $kind) {
            self::assertFalse($reader->isIncremental($kind), $kind->value);
        }
        try {
            $reader->node(Kind::Contact, 'gid://salesforce/Contact/003');
            self::fail('records are read in runs');
        } catch (SourceError $e) {
            self::assertStringContainsString('read in runs', $e->getMessage());
        }
        $this->expectException(SourceError::class);
        $reader->shop();
    }

    public function testAnInstanceUrlIsRequired(): void
    {
        $this->expectException(SourceError::class);
        SalesforceReader::connect('', BearerAuthorizer::token(self::TOKEN, 'Salesforce'), self::transport('v1'), new RecordingSleeper());
    }

    private function reader(ScriptedTransport $transport): SalesforceReader
    {
        return SalesforceReader::connect(self::INSTANCE, BearerAuthorizer::token(self::TOKEN, 'Salesforce'), $transport, new RecordingSleeper());
    }

    private static function transport(string $state): ScriptedTransport
    {
        return new ScriptedTransport(new FixtureResponder('salesforce', $state));
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
