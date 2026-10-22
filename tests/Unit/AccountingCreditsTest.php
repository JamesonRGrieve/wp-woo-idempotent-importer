<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Mapping\CreditContract;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\FreshBooks\FreshBooksReader;
use IdempotentImporter\Source\FreshBooks\Mapping\CreditNoteMapper;
use IdempotentImporter\Source\FreshBooks\Mapping\StoreCreditMapper as FreshBooksStoreCredit;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\CreditMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\StoreCreditMapper as QuickBooksStoreCredit;
use IdempotentImporter\Source\QuickBooksOnline\QuickBooksOnlineReader;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use IdempotentImporter\Tests\Support\TestSettings;
use PHPUnit\Framework\TestCase;

/** Credit notes, credit memos and refund receipts: refunds on mirrored orders, and the rest as store credit. */
final class AccountingCreditsTest extends TestCase
{
    public function testAFreshBooksCreditNoteAppliedToAnInvoiceIsARefundOnIt(): void
    {
        self::assertNull((new CreditNoteMapper())->map(self::nodes(self::freshBooks('v1'), Kind::Return)[0]), 'applied to nothing: no refund');

        $return = Values::of((new CreditNoteMapper())->map(self::nodes(self::freshBooks('v2'), Kind::Return)[0]) ?? throw new \LogicException('no refund'));
        self::assertSame(['gid://freshbooks/Invoice/404'], $return->strings('order_gids'));
        [$refund] = $return->records('refunds');
        self::assertSame(['gid://freshbooks/CreditApplication/504', '15', 'Credit note CN-0001 applied', '2026-09-12T00:00:00Z'], [$refund->string('gid'), $refund->string('amount'), $refund->string('note'), $refund->string('created_at')]);
        self::assertSame([['name' => 'Glaze kit (cracked)', 'description' => 'Goodwill credit', 'quantity' => '1', 'unit_price' => '25', 'amount' => '25']], $refund->raw('credit_lines'), "the credit note's own lines");
        self::assertSame([], $refund->records('lines'), 'no order line is guessed');
    }

    public function testWhatIsLeftOfAFreshBooksClientsCreditNotesIsTheirStoreCredit(): void
    {
        $v1 = Values::of((new FreshBooksStoreCredit(self::settings()))->map(self::nodes(self::freshBooks('v1'), Kind::GiftCard)[0]));
        self::assertSame(['store_credit', '25', 'CAD', 'gid://freshbooks/Customer/302', 'theo@freshbooks.example'], [$v1->string('kind'), $v1->string('balance'), $v1->string('currency'), $v1->string('customer_gid'), $v1->nullableString('email')]);
        self::assertNull($v1->nullableString('code'), 'a new number, shown in their account');

        $v2 = Values::of((new FreshBooksStoreCredit(self::settings()))->map(self::nodes(self::freshBooks('v2'), Kind::GiftCard)[0]));
        self::assertSame('10', $v2->string('balance'), '25 issued, 15 applied');
        self::assertSame([['issue', '25', 'CN-0001', '2026-09-01T00:00:00Z'], ['redeem', '-15', 'Invoice 404', '2026-09-12T00:00:00Z']], array_map(
            static fn(Values $entry): array => [$entry->string('kind'), $entry->string('amount'), $entry->nullableString('reference'), $entry->nullableString('at')],
            $v2->records('ledger'),
        ));
    }

    public function testAQuickBooksCreditMemoAppliedByAPaymentIsARefundOnTheInvoiceItPays(): void
    {
        $nodes = self::nodes(self::quickBooks('v1'), Kind::Return);
        self::assertCount(1, $nodes, 'v1 has a credit memo and no refund receipt');
        self::assertNull((new CreditMapper())->map($nodes[0]), 'not applied yet');

        [$memo, $receipt] = array_map(static fn(Node $node): ?\IdempotentImporter\Mapping\Desired => (new CreditMapper())->map($node), self::nodes(self::quickBooks('v2'), Kind::Return));
        $memo = Values::of($memo ?? throw new \LogicException('no refund'));
        [$refund] = $memo->records('refunds');
        self::assertSame(['gid://quickbooks_online/Invoice/131', '15', 'Credit memo CM-1001 applied'], [$refund->string('order_gid'), $refund->string('amount'), $refund->string('note')]);
        self::assertSame([['name' => 'Celadon glaze, 1 L', 'item_id' => '2', 'quantity' => '1', 'unit_price' => '20', 'amount' => '20']], $refund->raw('credit_lines'));

        $receipt = Values::of($receipt ?? throw new \LogicException('no refund'));
        [$refund] = $receipt->records('refunds');
        self::assertSame(['gid://quickbooks_online/SalesReceipt/140', '3', 'Refund receipt RR-1 Cash'], [$refund->string('order_gid'), $refund->string('amount'), $refund->string('note')], "a refund receipt refunds its sale's order");
    }

    public function testACreditMemoAppliedByAPaymentOfSeveralInvoicesIsNotGuessed(): void
    {
        $memo = new Node(['id' => 'gid://quickbooks_online/CreditMemo/1', 'Id' => '1', 'DocNumber' => 'CM-1', 'Line' => [], 'applications' => [
            ['payment_id' => '9', 'date' => '2026-09-01', 'amount' => '15', 'invoice_ids' => ['131', '132']],
        ]], 'gid://quickbooks_online/CreditMemo/1');

        self::assertNull((new CreditMapper())->map($memo));
        $refundReceipt = new Node(['id' => 'gid://quickbooks_online/RefundReceipt/2', 'Id' => '2', 'TotalAmt' => 3], 'gid://quickbooks_online/RefundReceipt/2');
        $this->expectException(Unsupported::class);
        (new CreditMapper())->map($refundReceipt);
    }

    public function testWhatIsLeftOfAQuickBooksCustomersCreditMemosIsTheirStoreCredit(): void
    {
        $v1 = Values::of((new QuickBooksStoreCredit(self::settings()))->map(self::nodes(self::quickBooks('v1'), Kind::GiftCard)[0]));
        self::assertSame(['20', 'USD', 'gid://quickbooks_online/Customer/11', 'sam@qbo.example'], [$v1->string('balance'), $v1->string('currency'), $v1->string('customer_gid'), $v1->nullableString('email')]);
        $v2 = Values::of((new QuickBooksStoreCredit(self::settings()))->map(self::nodes(self::quickBooks('v2'), Kind::GiftCard)[0]));
        self::assertSame('5', $v2->string('balance'), "QuickBooks' remaining credit");
    }

    public function testAPlaceholderEmailIsNeverGivenToSquareAndOverspentCreditIsRefused(): void
    {
        $card = Values::of(CreditContract::storeCredit('gid://wave/StoreCredit/1', self::settings(), 'cad', 'gid://wave/Customer/1', 'customer-1@wave.invalid', 'Walk', 'In', [
            CreditContract::credit('gid://wave/Credit/1', 'C1', null, '10', []),
        ]));
        self::assertSame(['10', 'CAD', null], [$card->string('balance'), $card->string('currency'), $card->nullableString('email')]);

        $this->expectException(Unsupported::class);
        CreditContract::storeCredit('gid://wave/StoreCredit/1', self::settings(), 'CAD', 'gid://wave/Customer/1', null, null, null, [
            CreditContract::credit('gid://wave/Credit/1', 'C1', null, '10', [CreditContract::application('gid://wave/Applied/1', null, '12', null)]),
        ]);
    }

    /** @return list<Node> every node of a kind */
    private static function nodes(SourceReader $reader, Kind $kind): array
    {
        return $reader->page($kind, null, null, '')->nodes;
    }

    private static function settings(): Settings
    {
        return TestSettings::all();
    }

    private static function freshBooks(string $state): FreshBooksReader
    {
        return FreshBooksReader::connect('https://api.freshbooks.test', BearerAuthorizer::token('t', 'FreshBooks'), new ScriptedTransport(new FixtureResponder('freshbooks', $state)), new RecordingSleeper(), null);
    }

    private static function quickBooks(string $state): QuickBooksOnlineReader
    {
        return QuickBooksOnlineReader::connect('https://quickbooks.api.test', '9341453177', BearerAuthorizer::token('t', 'QuickBooks Online'), new ScriptedTransport(new FixtureResponder('quickbooks_online', $state)), new RecordingSleeper());
    }
}
