<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\FreshBooks\FreshBooksReader;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class FreshBooksReaderTest extends TestCase
{
    private const BASE = 'https://api.freshbooks.test';

    public function testTheBusinessIsFoundFromTheLoginAndItemsThenServicesAreRead(): void
    {
        $transport = self::transport('v1');
        $reader = $this->reader($transport);

        $items = $reader->page(Kind::Product, null, null, '');
        $services = $reader->page(Kind::Product, $items->endCursor, null, '');

        self::assertSame(self::BASE . FreshBooksReader::IDENTITY_PATH, $transport->requests[0]['url']);
        self::assertStringStartsWith(self::BASE . '/accounting/account/fbAcc1/items/items?page=1&per_page=100', $transport->requests[1]['url']);
        self::assertSame('Bearer t', $transport->requests[0]['headers']['Authorization']);
        self::assertSame(['gid://freshbooks/Item/101', 'gid://freshbooks/Item/102'], self::gids($items->nodes), 'a deleted item is left out');
        self::assertSame(['services:1', true], [$items->endCursor, $items->hasNextPage]);
        self::assertSame(['gid://freshbooks/Service/201'], self::gids($services->nodes));
        self::assertSame('75.00', $services->nodes[0]->requiredChild('rate')->string('rate'));
        self::assertFalse($services->hasNextPage);
    }

    public function testClientsAreCustomersAndContactsAndTheOrganizationsTheyNameAreCompanies(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $customers = $reader->page(Kind::Customer, null, null, '')->nodes;
        $contacts = $reader->page(Kind::Contact, null, null, '')->nodes;
        $companies = $reader->page(Kind::CrmCompany, null, null, '')->nodes;

        self::assertSame(['gid://freshbooks/Customer/301', 'gid://freshbooks/Customer/302', 'gid://freshbooks/Customer/303'], self::gids($customers));
        self::assertSame('gid://freshbooks/Contact/301', $contacts[0]->id());
        self::assertCount(1, $companies, 'one organization, however it is spelled');
        self::assertSame([301, 302], $companies[0]->ints('client_ids'));
        self::assertSame($companies[0]->id(), $contacts[1]->string('organization_gid'));
        self::assertNull($contacts[2]->optionalString('organization_gid'));
    }

    public function testInvoicesComeWithTheirLinesAndPaymentsOldestFirst(): void
    {
        $transport = self::transport('v1');

        $invoices = $this->reader($transport)->page(Kind::Order, null, null, '')->nodes;

        self::assertStringContainsString('include%5B%5D=lines', $transport->requests[1]['url']);
        self::assertSame([501, 502], array_map(static fn(Node $payment): int => $payment->int('id'), $invoices[0]->list('payments')));
        self::assertSame([], $invoices[1]->list('payments'));
        self::assertCount(2, $invoices[0]->list('lines'));
    }

    public function testAnAccountIdChoosesTheBusinessAndOneTheLoginIsntAMemberOfIsAnError(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('not a member of account other');
        FreshBooksReader::connect(self::BASE, BearerAuthorizer::token('t', 'FreshBooks'), self::transport('v1'), new RecordingSleeper(), 'other')
            ->page(Kind::Customer, null, null, '');
    }

    public function testSeveralBusinessesWithoutAnAccountIdIsAnError(): void
    {
        $memberships = ['response' => ['business_memberships' => [
            ['business' => ['id' => 1, 'business_uuid' => 'a', 'account_id' => 'A1']],
            ['business' => ['id' => 2, 'business_uuid' => 'b', 'account_id' => 'B2']],
        ]]];
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('several businesses: enter the account id to mirror (A1, B2)');
        $this->reader(new ScriptedTransport(null, [new HttpResponse(200, (string) json_encode($memberships))]))->page(Kind::Customer, null, null, '');
    }

    public function testRequestsArePacedAndRejectedTokensStopTheRun(): void
    {
        $sleeper = new RecordingSleeper();
        $reader = FreshBooksReader::connect(self::BASE, BearerAuthorizer::token('t', 'FreshBooks'), self::transport('v1'), $sleeper, null);
        $reader->page(Kind::Customer, null, null, '');
        self::assertNotSame([], $sleeper->waits, 'the second request waits its turn');

        $this->expectException(CredentialsRejected::class);
        $this->reader(new ScriptedTransport(null, [new HttpResponse(401, '{}')]))->page(Kind::Customer, null, null, '');
    }

    public function testNothingIsIncrementalAndThereAreNoStoreSettings(): void
    {
        $reader = $this->reader(self::transport('v1'));
        foreach (Kind::SYNC_ORDER as $kind) {
            self::assertFalse($reader->isIncremental($kind), $kind->value);
        }
        $this->expectException(SourceError::class);
        $reader->shop();
    }

    private function reader(ScriptedTransport $transport): FreshBooksReader
    {
        return FreshBooksReader::connect(self::BASE, BearerAuthorizer::token('t', 'FreshBooks'), $transport, new RecordingSleeper(), null);
    }

    private static function transport(string $state): ScriptedTransport
    {
        return new ScriptedTransport(new FixtureResponder('freshbooks', $state));
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
