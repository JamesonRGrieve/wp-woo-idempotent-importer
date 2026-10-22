<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\CategoryMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\CompanyMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\ContactMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\CustomerMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\ProductMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\SaleMapper;
use IdempotentImporter\Source\QuickBooksOnline\QuickBooksOnlineReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class QuickBooksOnlineMapperTest extends TestCase
{
    public function testCategoriesAndItems(): void
    {
        $reader = $this->reader('v1');
        $glazes = Values::of((new CategoryMapper())->map($reader->page(Kind::Collection, null, null, '')->nodes[0]));
        [$celadon, $firing, $wrap] = array_map(static fn(Node $node): Values => Values::of((new ProductMapper(false))->map($node)), array_slice($reader->page(Kind::Product, null, null, '')->nodes, 0, 3));

        self::assertSame(['Glazes', null], [$glazes->string('name'), $glazes->nullableString('parent_gid')]);
        $variant = $celadon->records('variants')[0];
        self::assertSame(['32.5', 'CEL-1', '14', true, 20, true], [$variant->string('regular_price'), $variant->nullableString('sku'), $variant->nullableString('cost'), $variant->bool('manage_stock'), $variant->nullableInt('stock_quantity'), $variant->bool('taxable')]);
        self::assertSame(['gid://quickbooks_online/Category/1'], $celadon->strings('collections'));
        self::assertSame(['publish', '2026-01-04T18:01:00Z'], [$celadon->string('status'), $celadon->nullableString('created_at')]);
        self::assertSame([true, false, null], [$firing->records('variants')[0]->bool('virtual'), $firing->records('variants')[0]->bool('manage_stock'), $firing->records('variants')[0]->nullableInt('stock_quantity')]);
        self::assertSame('private', $wrap->string('status'), 'inactive');
    }

    public function testABundleIsNotMirroredWithoutABundlesPlugin(): void
    {
        $this->expectException(Unsupported::class);
        (new ProductMapper(false))->map($this->reader('v1')->page(Kind::Product, null, null, '')->nodes[3]);
    }

    public function testCustomersContactsAndCompanies(): void
    {
        $reader = $this->reader('v1');
        $juniper = Values::of((new CustomerMapper())->map($reader->page(Kind::Customer, null, null, '')->nodes[0]));
        $maya = Values::of((new ContactMapper())->map($reader->page(Kind::Contact, null, null, '')->nodes[0]));
        $company = Values::of((new CompanyMapper())->map($reader->page(Kind::CrmCompany, null, null, '')->nodes[0]));

        self::assertSame(['maya@qbo.example', 'qbo-10', 'Maya', 'Chen', '(503) 555-0110', 'Wholesale account.'], [$juniper->nullableString('email'), $juniper->string('username'), $juniper->nullableString('first_name'), $juniper->nullableString('last_name'), $juniper->nullableString('phone'), $juniper->nullableString('note')]);
        self::assertSame(['first_name' => 'Maya', 'last_name' => 'Chen', 'company' => 'Juniper Studio', 'address_1' => '40 Alder Street', 'city' => 'Portland', 'state' => 'OR', 'postcode' => '97201', 'country' => 'USA', 'phone' => '(503) 555-0110'], $juniper->record('billing')?->all());
        self::assertSame(['customer', ['gid://quickbooks_online/Company/10']], [$maya->string('contact_type'), $maya->strings('company_gids')]);
        self::assertSame(['Juniper Studio', 'https://juniper.example', 'Portland'], [$company->string('name'), $company->nullableString('website'), $company->record('address')?->string('city')]);
    }

    public function testACustomerWithoutAnEmailGetsAPlaceholder(): void
    {
        $reader = $this->reader('v1');
        $customer = Values::of((new CustomerMapper())->map($reader->page(Kind::Customer, null, null, '')->nodes[2]));
        $contact = Values::of((new ContactMapper())->map($reader->page(Kind::Contact, null, null, '')->nodes[2]));

        self::assertSame('customer-12@quickbooks-online.invalid', $customer->string('email'));
        self::assertSame($customer->string('email'), $contact->string('email'));
        self::assertTrue($customer->bool('email_placeholder') && $contact->bool('email_placeholder'));
    }

    public function testAPaidInvoiceWithABundleDiscountShippingTaxAndPayment(): void
    {
        $invoice = Values::of((new SaleMapper())->map($this->reader('v1')->page(Kind::Order, null, null, '')->nodes[0]));

        self::assertSame(['1037', 'USD', '2026-08-04T00:00:00Z', 'gid://quickbooks_online/Customer/10', 'maya@qbo.example'], [$invoice->string('name'), $invoice->string('currency'), $invoice->string('created_at'), $invoice->nullableString('customer_gid'), $invoice->nullableString('email')]);
        self::assertSame(['152.45', '10', '12', '7.95'], [$invoice->string('total'), $invoice->string('discount_total'), $invoice->string('shipping_total'), $invoice->string('tax_total')]);
        self::assertSame([['title' => 'Studio district tax', 'rate' => '0.06', 'order_amount' => '7.95']], $invoice->raw('tax_lines'));
        self::assertSame([['title' => 'Ground', 'code' => null, 'total' => '12', 'taxes' => []]], $invoice->raw('shipping_lines'));
        $lines = $invoice->records('line_items');
        self::assertSame([['gid://quickbooks_online/Item/2', 2, '65'], ['gid://quickbooks_online/Item/2', 1, '32.5'], ['gid://quickbooks_online/Item/3', 1, '45']], array_map(static fn(Values $line): array => [$line->nullableString('product_gid'), $line->int('quantity'), $line->string('total')], $lines), "the bundle's own lines");
        self::assertSame(['completed', '2026-08-10T00:00:00Z', 'Check', '1042'], [$invoice->string('status'), $invoice->nullableString('processed_at'), $invoice->nullableString('payment_method_title'), $invoice->nullableString('transaction_id')]);
        self::assertSame(['Payment of 152.45 USD by Check (ref. 1042) on 2026-08-10.'], array_map(static fn(Values $note): string => $note->string('message'), $invoice->records('notes')));
        self::assertSame(['JS-88', 'Invoice'], [$invoice->record('sale')?->string('PONumber'), $invoice->record('sale')?->string('type')]);
    }

    public function testAnUnpaidInvoiceIsPendingAndASalesReceiptIsCompleted(): void
    {
        $reader = $this->reader('v1');
        $invoices = $reader->page(Kind::Order, null, null, '');
        $unpaid = Values::of((new SaleMapper())->map($invoices->nodes[1]));
        $receipt = Values::of((new SaleMapper())->map($reader->page(Kind::Order, $invoices->endCursor, null, '')->nodes[0]));

        self::assertSame(['pending', null, []], [$unpaid->string('status'), $unpaid->nullableString('processed_at'), $unpaid->raw('tax_lines')]);
        self::assertSame(['completed', '2026-08-22T00:00:00Z', 'gid://quickbooks_online/Customer/12', 'Cash', 'R-140'], [$receipt->string('status'), $receipt->nullableString('processed_at'), $receipt->nullableString('customer_gid'), $receipt->nullableString('payment_method_title'), $receipt->nullableString('transaction_id')]);
        self::assertSame(['first_name' => 'Cash Customer'], $receipt->record('billing')?->all());
    }

    private function reader(string $state): QuickBooksOnlineReader
    {
        return QuickBooksOnlineReader::connect('https://quickbooks.api.intuit.test', '9341453177', BearerAuthorizer::token('t', 'QuickBooks Online'), new ScriptedTransport(new FixtureResponder('quickbooks_online', $state)), new RecordingSleeper());
    }
}
