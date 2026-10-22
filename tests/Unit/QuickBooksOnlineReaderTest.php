<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\QuickBooksOnline\QuickBooksOnlineReader;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class QuickBooksOnlineReaderTest extends TestCase
{
    private const BASE = 'https://quickbooks.api.intuit.test';
    private const REALM = '9341453177';

    public function testItemsAreQueriedOnceAndSplitIntoCategoriesAndProducts(): void
    {
        $transport = self::transport('v1');
        $reader = $this->reader($transport);

        $categories = $reader->page(Kind::Collection, null, null, '')->nodes;
        $products = $reader->page(Kind::Product, null, null, '')->nodes;

        self::assertCount(1, $transport->requests, 'the items are read once per run');
        $query = self::query($transport->requests[0]['url']);
        self::assertSame(['SELECT * FROM Item WHERE Active IN (true, false) ORDERBY MetaData.CreateTime STARTPOSITION 1 MAXRESULTS 1000', '75'], [$query['query'], $query['minorversion']]);
        self::assertStringStartsWith(self::BASE . '/v3/company/' . self::REALM . '/query?', $transport->requests[0]['url']);
        self::assertSame(['gid://quickbooks_online/Category/1'], self::gids($categories));
        self::assertSame(['gid://quickbooks_online/Item/2', 'gid://quickbooks_online/Item/3', 'gid://quickbooks_online/Item/4', 'gid://quickbooks_online/Item/5'], self::gids($products));
        self::assertSame(['1', null], [$products[0]->optionalString('category_id'), $products[1]->optionalString('category_id')]);
    }

    public function testCustomersAreCustomersContactsAndCompanies(): void
    {
        $reader = $this->reader(self::transport('v1'));

        self::assertSame(['gid://quickbooks_online/Customer/10', 'gid://quickbooks_online/Customer/11', 'gid://quickbooks_online/Customer/12'], self::gids($reader->page(Kind::Customer, null, null, '')->nodes));
        self::assertSame(['gid://quickbooks_online/Company/10'], self::gids($reader->page(Kind::CrmCompany, null, null, '')->nodes), 'only the customer with a company name');
        self::assertSame('gid://quickbooks_online/Contact/11', $reader->page(Kind::Contact, null, null, '')->nodes[1]->id());
    }

    public function testInvoicesThenSalesReceiptsWithTheirCustomersPaymentsTaxRatesAndCurrency(): void
    {
        $reader = $this->reader(self::transport('v1'));

        $invoices = $reader->page(Kind::Order, null, null, '');
        $receipts = $reader->page(Kind::Order, $invoices->endCursor, null, '');

        self::assertSame(['SalesReceipt:1', true], [$invoices->endCursor, $invoices->hasNextPage]);
        self::assertSame(['gid://quickbooks_online/Invoice/130', 'gid://quickbooks_online/Invoice/131'], self::gids($invoices->nodes));
        $first = $invoices->nodes[0];
        self::assertSame(['Invoice', 'Juniper Studio', 'USD', 'Studio district tax'], [$first->string('record_type'), $first->requiredChild('customer')->string('CompanyName'), $first->string('home_currency'), $first->requiredChild('tax_rates')->string('3')]);
        self::assertSame(['501'], array_map(static fn(Node $payment): string => $payment->string('Id'), $first->list('payments')));
        self::assertSame('152.45', $first->list('payments')[0]->decimal('applied'));
        self::assertSame(['gid://quickbooks_online/SalesReceipt/140'], self::gids($receipts->nodes));
        self::assertFalse($receipts->hasNextPage);
        self::assertSame([], $receipts->nodes[0]->list('payments'), 'a sales receipt is paid when made');
    }

    public function testRejectedTokensStopTheRunAndARealmIsRequired(): void
    {
        try {
            $this->reader(new ScriptedTransport(null, [new HttpResponse(401, '{"Fault":{"type":"AUTHENTICATION"}}'), new HttpResponse(401, '{}')]))->page(Kind::Customer, null, null, '');
            self::fail('rejected');
        } catch (CredentialsRejected) {
            $this->expectException(SourceError::class);
            QuickBooksOnlineReader::connect(self::BASE, '', BearerAuthorizer::token('t', 'QuickBooks Online'), self::transport('v1'), new RecordingSleeper());
        }
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

    private function reader(ScriptedTransport $transport): QuickBooksOnlineReader
    {
        return QuickBooksOnlineReader::connect(self::BASE, self::REALM, BearerAuthorizer::token('t', 'QuickBooks Online'), $transport, new RecordingSleeper());
    }

    private static function transport(string $state): ScriptedTransport
    {
        return new ScriptedTransport(new FixtureResponder('quickbooks_online', $state));
    }

    /** @return array<string, string> a request's query parameters, decoded */
    private static function query(string $url): array
    {
        $query = [];
        foreach (explode('&', (string) parse_url($url, PHP_URL_QUERY)) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[rawurldecode($name)] = rawurldecode($value);
        }

        return $query;
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
