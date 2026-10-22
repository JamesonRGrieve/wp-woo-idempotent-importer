<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\FreshBooks\FreshBooksReader;
use IdempotentImporter\Source\FreshBooks\Mapping\ContactMapper;
use IdempotentImporter\Source\FreshBooks\Mapping\CustomerMapper;
use IdempotentImporter\Source\FreshBooks\Mapping\InvoiceMapper;
use IdempotentImporter\Source\FreshBooks\Mapping\OrganizationMapper;
use IdempotentImporter\Source\FreshBooks\Mapping\ProductMapper;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class FreshBooksMapperTest extends TestCase
{
    public function testItemsAndServicesAreProducts(): void
    {
        $reader = $this->reader('v1');
        $items = $reader->page(Kind::Product, null, null, '');
        $services = $reader->page(Kind::Product, $items->endCursor, null, '');
        [$wash, $wheel] = array_map(static fn(Node $node): Values => Values::of((new ProductMapper(false))->map($node)), $items->nodes);
        $consultation = Values::of((new ProductMapper(true))->map($services->nodes[0]));

        $variant = $wash->records('variants')[0];
        self::assertSame(['Kiln wash, 1 kg', 'publish', '<p>Protects kiln shelves.</p>'], [$wash->string('title'), $wash->string('status'), $wash->string('description_html')]);
        self::assertSame(['18', 'FB-WASH', true, 12, false], [$variant->string('regular_price'), $variant->nullableString('sku'), $variant->bool('manage_stock'), $variant->nullableInt('stock_quantity'), $variant->bool('virtual')]);
        self::assertSame(['private', false], [$wheel->string('status'), $wheel->records('variants')[0]->bool('manage_stock')], 'archived; no inventory count');
        $service = $consultation->records('variants')[0];
        self::assertSame(['75', true, 'freshbooks-service-201'], [$service->string('regular_price'), $service->bool('virtual'), $service->nullableString('sku')]);
        self::assertSame(['billable' => true], $consultation->raw('product'));
    }

    public function testClientsAreCustomersContactsAndCompanies(): void
    {
        $reader = $this->reader('v1');
        $mira = Values::of((new CustomerMapper())->map($reader->page(Kind::Customer, null, null, '')->nodes[0]));
        $contacts = $reader->page(Kind::Contact, null, null, '')->nodes;
        $miraContact = Values::of((new ContactMapper())->map($contacts[0]));
        $company = Values::of((new OrganizationMapper())->map($reader->page(Kind::CrmCompany, null, null, '')->nodes[0]));

        self::assertSame(['mira@freshbooks.example', 'freshbooks-301', 'Mira', '+1 250 555 0301'], [$mira->nullableString('email'), $mira->string('username'), $mira->nullableString('first_name'), $mira->nullableString('phone')]);
        self::assertSame(['first_name' => 'Mira', 'last_name' => 'Okafor', 'company' => 'Kilnworks Collective', 'address_1' => '12 Clay Street', 'city' => 'Victoria', 'state' => 'BC', 'postcode' => 'V8W 1A1', 'country' => 'Canada', 'phone' => '+1 250 555 0301'], $mira->record('billing')?->all());
        self::assertSame('en', $mira->record('customer')?->string('language'), 'a language code, kept as meta');
        self::assertSame(['mira@freshbooks.example', 'transactional', 'customer', 'FreshBooks'], [$miraContact->string('email'), $miraContact->string('status'), $miraContact->string('contact_type'), $miraContact->string('source')]);
        self::assertSame([$contacts[0]->string('organization_gid')], $miraContact->strings('company_gids'));
        self::assertSame('V8W 1A1', $miraContact->record('address')?->string('postal_code'));
        self::assertSame(['Kilnworks Collective', '+1 250 555 0301', 'Victoria'], [$company->string('name'), $company->nullableString('phone'), $company->record('address')?->string('city')]);
    }

    public function testAClientWithoutAnEmailIsNoContact(): void
    {
        $this->expectException(Unsupported::class);
        (new ContactMapper())->map($this->reader('v1')->page(Kind::Contact, null, null, '')->nodes[2]);
    }

    public function testAClientWithoutAnEmailIsNoCustomerAndItsInvoicesAreGuestOrders(): void
    {
        $guest = Values::of($this->desired('v2', Kind::Order, new InvoiceMapper())[2]);

        self::assertNull($guest->nullableString('customer_gid'));
        self::assertSame(['first_name' => 'Walk', 'last_name' => 'In'], $guest->record('billing')?->all(), "the client's name");
        $this->expectException(Unsupported::class);
        (new CustomerMapper())->map($this->reader('v1')->page(Kind::Customer, null, null, '')->nodes[2]);
    }

    public function testAPaidInvoiceWithItsDiscountTaxesAndPayments(): void
    {
        [$paid, $sent] = $this->desired('v1', Kind::Order, new InvoiceMapper());
        $order = Values::of($paid);

        self::assertSame([], $paid->warnings);
        self::assertSame(['0000101', 'CAD', '2026-08-03T00:00:00Z', 'gid://freshbooks/Customer/301'], [$order->string('name'), $order->string('currency'), $order->string('created_at'), $order->nullableString('customer_gid')]);
        self::assertSame(['142.6', '14.85', '8.95'], [$order->string('total'), $order->string('discount_total'), $order->string('tax_total')]);
        self::assertSame([
            ['title' => 'GST', 'rate' => '0.05', 'order_amount' => '6.68'],
            ['title' => 'PST', 'rate' => '0.07', 'order_amount' => '2.27'],
        ], $order->raw('tax_lines'), 'each tax on the discounted lines it is on, to the cent');
        self::assertSame(['completed', '2026-08-10T00:00:00Z', 'Credit Card'], [$order->string('status'), $order->nullableString('processed_at'), $order->nullableString('payment_method_title')]);
        [$check, $card] = $order->records('notes');
        self::assertSame(['Payment of 50.00 CAD by Check on 2026-08-05.', '2026-08-05T00:00:00Z'], [$check->string('message'), $check->string('created_at')]);
        self::assertSame('Payment of 92.60 CAD by Credit Card on 2026-08-10: Visa ending 4242', $card->string('message'));
        [$wash, $consultation] = $order->records('line_items');
        self::assertSame(['Kiln wash, 1 kg', 2, '36', null], [$wash->string('name'), $wash->int('quantity'), $wash->string('total'), $wash->nullableString('product_gid')]);
        self::assertSame(['Description' => 'Ash and celadon tests.', 'Quantity' => '1.5', 'Unit cost' => '75'], $consultation->stringMap('attributes'), 'a fractional quantity is kept');
        self::assertSame(['first_name' => 'Mira', 'last_name' => 'Okafor', 'company' => 'Kilnworks Collective', 'address_1' => '12 Clay Street', 'city' => 'Victoria', 'state' => 'BC', 'postcode' => 'V8W 1A1', 'country' => 'Canada'], $order->record('billing')?->all());
        self::assertSame(['PO-77', 'Net 15', 'paid'], [$order->record('invoice')?->string('po_number'), $order->record('invoice')?->string('terms'), $order->record('invoice')?->string('v3_status')]);
        self::assertSame(['pending', null, []], [Values::of($sent)->string('status'), Values::of($sent)->nullableString('processed_at'), Values::of($sent)->raw('tax_lines')], 'sent, unpaid, untaxed');
    }

    public function testADisputedInvoiceIsOnHold(): void
    {
        $orders = $this->desired('v2', Kind::Order, new InvoiceMapper());

        self::assertSame(['completed', 'on-hold'], [Values::of($orders[1])->string('status'), Values::of($orders[2])->string('status')]);
    }

    public function testTaxesThatDontMakeTheAmountAreBookedAsOneWithAWarning(): void
    {
        $line = ['lineid' => 1, 'name' => 'Clay', 'qty' => '1', 'unit_cost' => ['amount' => '10.00', 'code' => 'CAD'], 'amount' => ['amount' => '10.00', 'code' => 'CAD'], 'taxName1' => 'GST', 'taxAmount1' => '5'];
        $invoice = new Node(['id' => 'gid://freshbooks/Invoice/9', 'invoice_number' => '9', 'currency_code' => 'CAD', 'create_date' => '2026-08-01', 'customerid' => 1,
            'amount' => ['amount' => '10.60', 'code' => 'CAD'], 'outstanding' => ['amount' => '10.60', 'code' => 'CAD'], 'lines' => [$line], 'payments' => []]);

        $desired = (new InvoiceMapper())->map($invoice);

        self::assertSame([['title' => 'Tax', 'rate' => null, 'order_amount' => '0.6']], Values::of($desired)->raw('tax_lines'));
        self::assertSame([sprintf(InvoiceMapper::TAX_MISMATCH, '9', '0.5', '0.6')], $desired->warnings);
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

    private function reader(string $state): FreshBooksReader
    {
        return FreshBooksReader::connect('https://api.freshbooks.test', BearerAuthorizer::token('t', 'FreshBooks'), new ScriptedTransport(new FixtureResponder('freshbooks', $state)), new RecordingSleeper(), null);
    }
}
