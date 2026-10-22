<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\Wave\Mapping\CompanyMapper;
use IdempotentImporter\Source\Wave\Mapping\ContactMapper;
use IdempotentImporter\Source\Wave\Mapping\CustomerMapper;
use IdempotentImporter\Source\Wave\Mapping\InvoiceMapper;
use IdempotentImporter\Source\Wave\Mapping\ProductMapper;
use IdempotentImporter\Source\Wave\WaveReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class WaveMapperTest extends TestCase
{
    public function testProducts(): void
    {
        [$mug, , $clay] = array_map(Values::of(...), $this->desired('v1', Kind::Product, new ProductMapper(false)));
        $reader = $this->reader('v1');
        $vase = Values::of((new ProductMapper(true))->map($reader->page(Kind::Product, $reader->page(Kind::Product, null, null, '')->endCursor, null, '')->nodes[0]));

        $variant = $mug->records('variants')[0];
        self::assertSame(['Bisque mug blank', 'publish', '<p>Ready to glaze.</p>', '2026-08-01T16:00:00Z'], [$mug->string('title'), $mug->string('status'), $mug->string('description_html'), $mug->nullableString('created_at')]);
        self::assertSame(['8.5', null, false], [$variant->string('regular_price'), $variant->nullableString('sku'), $variant->bool('manage_stock')]);
        self::assertSame('private', $clay->string('status'), 'bought, not sold');
        self::assertSame(['private', 'wave-104'], [$vase->string('status'), $vase->records('variants')[0]->nullableString('sku')], 'archived; a generated SKU');
        self::assertSame([['id' => 'QnVzaW5lc3M6NWZiMWMwZGUtMWQyZS00YzNiLThhOWYtMDEyMzQ1Njc4OWFiO1NhbGVzVGF4OjE=', 'name' => 'Goods and Services Tax', 'abbreviation' => 'GST']], $mug->record('product')?->raw('defaultSalesTaxes'));
    }

    public function testCustomersContactsAndCompanies(): void
    {
        $reader = $this->reader('v1');
        $customers = $reader->page(Kind::Customer, null, null, '')->nodes;
        $cafe = Values::of((new CustomerMapper())->map($customers[0]));
        $lena = Values::of((new ContactMapper())->map($reader->page(Kind::Contact, null, null, '')->nodes[0]));
        $company = Values::of((new CompanyMapper())->map($reader->page(Kind::CrmCompany, null, null, '')->nodes[0]));

        self::assertSame(['lena@wave.example', 'wave-201', 'Lena', 'Ward'], [$cafe->nullableString('email'), $cafe->string('username'), $cafe->nullableString('first_name'), $cafe->nullableString('last_name')]);
        self::assertSame(['first_name' => 'Lena', 'last_name' => 'Ward', 'company' => 'Harbour Cafe', 'address_1' => '5 Wharf Street', 'city' => 'Victoria', 'state' => 'BC', 'postcode' => 'V8W 1T3', 'country' => 'CA', 'phone' => '+1 250 555 0201'], $cafe->record('billing')?->all());
        self::assertSame('Harbour Cafe kitchen', $cafe->record('shipping')?->string('first_name'));
        self::assertSame(['transactional', 'customer', ['gid://wave/Company/201']], [$lena->string('status'), $lena->string('contact_type'), $lena->strings('company_gids')]);
        self::assertSame(['British Columbia', 'Canada'], [$lena->record('address')?->string('state'), $lena->record('address')?->string('country')]);
        self::assertSame(['Harbour Cafe', 'https://harbour-cafe.example'], [$company->string('name'), $company->nullableString('website')]);
        self::assertSame([], Values::of((new ContactMapper())->map($reader->page(Kind::Contact, null, null, '')->nodes[1]))->strings('company_gids'), 'a person is no company');
    }

    public function testACustomerWithoutAnEmailGetsAPlaceholder(): void
    {
        $reader = $this->reader('v1');
        $customer = Values::of((new CustomerMapper())->map($reader->page(Kind::Customer, null, null, '')->nodes[2]));
        $contact = Values::of((new ContactMapper())->map($reader->page(Kind::Contact, null, null, '')->nodes[2]));

        self::assertStringEndsWith('@wave.invalid', $customer->string('email'));
        self::assertSame($customer->string('email'), $contact->string('email'), 'one placeholder per customer');
        self::assertTrue($customer->bool('email_placeholder') && $contact->bool('email_placeholder'));
    }

    public function testAPaidInvoiceWithItsItemsTaxesAndPayments(): void
    {
        [$paid, $sent] = array_map(Values::of(...), $this->desired('v1', Kind::Order, new InvoiceMapper()));

        self::assertSame(['1001', 'CAD', '2026-08-03T00:00:00Z', 'gid://wave/Customer/201'], [$paid->string('name'), $paid->string('currency'), $paid->string('created_at'), $paid->nullableString('customer_gid')]);
        self::assertSame(['218.65', '5', '18.65', 'completed', '2026-08-09T00:00:00Z'], [$paid->string('total'), $paid->string('discount_total'), $paid->string('tax_total'), $paid->string('status'), $paid->nullableString('processed_at')]);
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'order_amount' => null], ['title' => 'PST', 'rate' => '0.07', 'order_amount' => null]], $paid->raw('tax_lines'));
        [$mugs, $cleaning] = $paid->records('line_items');
        self::assertSame(['gid://wave/Product/101', 'gid://wave/Variant/101', 10, '85'], [$mugs->nullableString('product_gid'), $mugs->nullableString('variant_gid'), $mugs->int('quantity'), $mugs->string('total')]);
        self::assertSame([['title' => 'GST', 'rate' => '0.05', 'amount' => '6'], ['title' => 'PST', 'rate' => '0.07', 'amount' => '8.4']], $cleaning->raw('taxes'));
        self::assertSame(['Payment of 100.00 CAD by CASH on 2026-08-04.', 'Payment of 118.65 CAD by CREDIT_CARD on 2026-08-09.'], array_map(static fn(Values $note): string => $note->string('message'), $paid->records('notes')), 'a removed payment is left out');
        self::assertSame(['CREDIT_CARD', 'ch_3Q'], [$paid->nullableString('payment_method_title'), $paid->nullableString('transaction_id')]);
        self::assertSame('HC-7', $paid->record('invoice')?->string('poNumber'));
        self::assertSame(['pending', 'gid://wave/Customer/203'], [$sent->string('status'), $sent->nullableString('customer_gid')], 'unpaid; its customer has a placeholder email');
        self::assertSame(['first_name' => 'Cash Sale'], $sent->record('billing')?->all());
    }

    public function testAPartlyPaidInvoiceIsPendingWithAFractionalQuantityListed(): void
    {
        $partial = Values::of($this->desired('v2', Kind::Order, new InvoiceMapper())[2]);

        self::assertSame(['pending', null], [$partial->string('status'), $partial->nullableString('processed_at')]);
        self::assertSame(['Quantity' => '1.5', 'Unit price' => '9'], $partial->records('line_items')[0]->stringMap('attributes'));
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

    private function reader(string $state): WaveReader
    {
        return WaveReader::connect('https://gql.waveapps.test', BearerAuthorizer::token('t', 'Wave'), new ScriptedTransport(new FixtureResponder('wave', $state)), new RecordingSleeper(), null);
    }
}
