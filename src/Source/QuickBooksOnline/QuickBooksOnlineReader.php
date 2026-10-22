<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\QuickBooksOnline;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RateLimit;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\Fields;
use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;

/**
 * Reads one QuickBooks Online company (its realm) through the Accounting API's query endpoint
 * (`/v3/company/<realm>/query`, minor version 75): `SELECT * FROM <Entity>` in creation order, 1,000 a page (the
 * most a query returns), names with `Active IN (true, false)` (a query returns only active ones otherwise).
 *
 * - Collections are the items of type Category; products the other items, each with its category (its parent,
 *   when that is a category).
 * - Customers, contacts and companies (the customers with a company name) are its customers.
 * - Orders are its invoices, then its sales receipts, each with its customer, the company's tax rate names and home
 *   currency (`/preferences`), and an invoice with the payments applied to it (every payment is read once per run).
 * - Returns are its credit memos, each with how payments applied it (`applications`: a payment line linking the
 *   credit memo, with the one invoice the payment links, or every invoice when it links several), then its refund
 *   receipts. Gift cards are each customer's store credit: their credit memos with those applications.
 *
 * Nothing is incremental (unchanged records are skipped by hash). Intuit allows 500 requests a minute and 10 a
 * second per company and app (developer.intuit.com, API call limits and throttles); requests are paced at 450 a
 * minute, and a 429 (Intuit asks for 60 seconds) is retried with capped backoff, then pauses the run.
 */
final class QuickBooksOnlineReader implements SourceReader
{
    public const API_BASE = 'https://quickbooks.api.intuit.com';
    public const SANDBOX_API_BASE = 'https://sandbox-quickbooks.api.intuit.com';
    public const MINOR_VERSION = 75;
    public const PAGE_SIZE = 1000;
    public const REQUESTS_PER_MINUTE = 450;
    private const MINUTE_SECONDS = 60;
    private const CATEGORY = 'Category';
    private const NAMES = ['Item', 'Customer', 'TaxRate'];
    private const SALES = ['Invoice', 'SalesReceipt'];
    private const CURSOR_SEPARATOR = ':';
    private const INVOICE = 'Invoice';
    private const CREDIT_MEMO = 'CreditMemo';

    /** @var array<string, list<Node>> entity → every record, read once per run */
    private array $all = [];
    /** @var array<string, list<array<array-key, mixed>>>|null invoice id → the payments applied to it, oldest first */
    private ?array $payments = null;
    private ?string $homeCurrency = null;

    public function __construct(private readonly RestClient $client, private readonly string $realmId) {}

    public static function connect(string $apiBase, string $realmId, RequestAuthorizer $authorizer, HttpTransport $transport, Sleeper $sleeper): self
    {
        if (preg_match('/^\d+$/', $realmId) !== 1) {
            throw new SourceError('QuickBooks Online is not connected: use "Connect to QuickBooks Online" on the settings page.');
        }
        $rateLimit = new RateLimit(requestsPerSecond: RateLimit::perPeriod(self::REQUESTS_PER_MINUTE, self::MINUTE_SECONDS));

        return new self(new RestClient('QuickBooks Online', rtrim($apiBase, '/') . "/v3/company/{$realmId}", $authorizer, $transport, $sleeper, $rateLimit), $realmId);
    }

    public function shop(): Node
    {
        throw new SourceError('QuickBooks Online has no store settings to mirror.');
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        return match ($kind) {
            Kind::Collection => new Page(self::identified('Category', array_values(array_filter($this->every('Item'), self::isCategory(...)))), false, null),
            Kind::Product => new Page($this->products(), false, null),
            Kind::Customer => new Page(self::identified('Customer', $this->every('Customer')), false, null),
            Kind::CrmCompany => new Page(self::identified('Company', array_values(array_filter($this->every('Customer'), static fn(Node $customer): bool => Fields::text($customer, 'CompanyName') !== null))), false, null),
            Kind::Contact => new Page(self::identified('Contact', $this->every('Customer')), false, null),
            Kind::Order => $this->sales($cursor),
            Kind::Return => new Page([...self::identified('CreditMemo', $this->creditMemos()), ...self::identified('RefundReceipt', $this->withHomeCurrency($this->every('RefundReceipt')))], false, null),
            Kind::GiftCard => new Page($this->storeCredit(), false, null),
            default => throw new SourceError("QuickBooks Online has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        throw new SourceError("QuickBooks Online records are read in runs; a single {$kind->value} ({$gid}) is not read on its own.");
    }

    public function isIncremental(Kind $kind): bool
    {
        return false;
    }

    /** @return list<Node> the items that are products, each with its category */
    private function products(): array
    {
        $items = $this->every('Item');
        $categories = [];
        foreach ($items as $item) {
            if (self::isCategory($item)) {
                $categories[$item->string('Id')] = true;
            }
        }
        $products = [];
        foreach ($items as $item) {
            if (self::isCategory($item)) {
                continue;
            }
            $parent = Fields::ref($item, 'ParentRef');
            $products[] = new Node([...$item->raw(), 'category_id' => $parent !== null && isset($categories[$parent]) ? $parent : null]);
        }

        return self::identified('Item', $products);
    }

    /** Invoices, then sales receipts: the cursor is `<entity>:<start position>`. */
    private function sales(?string $cursor): Page
    {
        [$entity, $start] = array_pad(explode(self::CURSOR_SEPARATOR, $cursor ?? self::SALES[0] . self::CURSOR_SEPARATOR . '1', 2), 2, '1');
        $records = $this->query($entity, (int) $start);
        $customers = [];
        foreach ($this->every('Customer') as $customer) {
            $customers[$customer->string('Id')] = $customer->raw();
        }
        $rates = [];
        foreach ($this->every('TaxRate') as $rate) {
            $rates[$rate->string('Id')] = $rate->optionalString('Name');
        }
        $payments = $entity === self::INVOICE ? $this->payments() : [];
        $nodes = [];
        foreach ($records as $sale) {
            $nodes[] = new Node([
                ...$sale->raw(),
                'record_type' => $entity,
                'customer' => $customers[Fields::ref($sale, 'CustomerRef') ?? ''] ?? null,
                'payments' => $payments[$sale->string('Id')] ?? [],
                'tax_rates' => array_filter($rates, static fn(?string $name): bool => $name !== null),
                'home_currency' => $this->homeCurrency(),
            ]);
        }
        $identified = self::identified($entity, $nodes);
        if (count($records) === self::PAGE_SIZE) {
            return new Page($identified, true, $entity . self::CURSOR_SEPARATOR . ((int) $start + self::PAGE_SIZE));
        }
        $next = self::SALES[array_search($entity, self::SALES, true) + 1] ?? null;

        return new Page($identified, $next !== null, $next === null ? null : $next . self::CURSOR_SEPARATOR . '1');
    }

    /** @return array<string, list<array<array-key, mixed>>> invoice id → its payments, each with the amount applied to it */
    private function payments(): array
    {
        if ($this->payments !== null) {
            return $this->payments;
        }
        $byInvoice = [];
        foreach ($this->every('Payment') as $payment) {
            foreach (is_array($payment->raw()['Line'] ?? null) ? $payment->list('Line') : [] as $line) {
                foreach (is_array($line->raw()['LinkedTxn'] ?? null) ? $line->list('LinkedTxn') : [] as $linked) {
                    if ($linked->optionalString('TxnType') === self::INVOICE) {
                        $byInvoice[$linked->string('TxnId')][] = [...$payment->raw(), 'applied' => $line->optionalDecimal('Amount') ?? '0'];
                    }
                }
            }
        }
        foreach ($byInvoice as &$payments) {
            usort($payments, static fn(array $a, array $b): int => [$a['TxnDate'] ?? '', $a['Id'] ?? ''] <=> [$b['TxnDate'] ?? '', $b['Id'] ?? '']);
        }
        unset($payments);

        return $this->payments = $byInvoice;
    }

    /** @return list<Node> every credit memo, with how payments applied it and the home currency */
    private function creditMemos(): array
    {
        $applications = [];
        foreach ($this->every('Payment') as $payment) {
            $invoices = [];
            $credits = [];
            foreach (is_array($payment->raw()['Line'] ?? null) ? $payment->list('Line') : [] as $line) {
                foreach (is_array($line->raw()['LinkedTxn'] ?? null) ? $line->list('LinkedTxn') : [] as $linked) {
                    match ($linked->optionalString('TxnType')) {
                        self::INVOICE => $invoices[] = $linked->string('TxnId'),
                        self::CREDIT_MEMO => $credits[] = [$linked->string('TxnId'), $line->optionalDecimal('Amount') ?? '0'],
                        default => null,
                    };
                }
            }
            foreach ($credits as [$creditMemo, $amount]) {
                $applications[$creditMemo][] = [
                    'payment_id' => $payment->string('Id'),
                    'date' => $payment->optionalString('TxnDate'),
                    'amount' => $amount,
                    'invoice_ids' => array_values(array_unique($invoices)),
                ];
            }
        }

        return array_map(
            static fn(Node $memo): Node => new Node([...$memo->raw(), 'applications' => $applications[$memo->string('Id')] ?? []]),
            $this->withHomeCurrency($this->every('CreditMemo')),
        );
    }

    /** @return list<Node> each customer's store credit: their credit memos, with how they were applied */
    private function storeCredit(): array
    {
        $customers = [];
        foreach ($this->every('Customer') as $customer) {
            $customers[$customer->string('Id')] = $customer->raw();
        }
        $memos = [];
        foreach (self::identified('CreditMemo', $this->creditMemos()) as $memo) {
            $customer = Fields::ref($memo, 'CustomerRef');
            if ($customer !== null && isset($customers[$customer])) {
                $memos[$customer][] = $memo->raw();
            }
        }
        $credits = [];
        foreach ($memos as $customer => $list) {
            $credits[] = new Node(['Id' => (string) $customer, 'customer' => $customers[$customer], 'credit_memos' => $list, 'home_currency' => $this->homeCurrency()]);
        }

        return self::identified('StoreCredit', $credits);
    }

    /**
     * @param list<Node> $records
     *
     * @return list<Node> the records with the company's home currency, for one that names none
     */
    private function withHomeCurrency(array $records): array
    {
        $currency = $this->homeCurrency();

        return array_map(static fn(Node $record): Node => new Node([...$record->raw(), 'home_currency' => $currency]), $records);
    }

    /** @return list<Node> every record of an entity, read once per run */
    private function every(string $entity): array
    {
        if (isset($this->all[$entity])) {
            return $this->all[$entity];
        }
        $records = [];
        $start = 1;
        do {
            $page = $this->query($entity, $start);
            $records = [...$records, ...$page];
            $start += self::PAGE_SIZE;
        } while (count($page) === self::PAGE_SIZE);

        return $this->all[$entity] = $records;
    }

    /** @return list<Node> one page of an entity's records, oldest first */
    private function query(string $entity, int $start): array
    {
        $where = in_array($entity, self::NAMES, true) ? ' WHERE Active IN (true, false)' : '';
        $answer = $this->client->get('/query', [
            'query' => "SELECT * FROM {$entity}{$where} ORDERBY MetaData.CreateTime STARTPOSITION {$start} MAXRESULTS " . self::PAGE_SIZE,
            'minorversion' => self::MINOR_VERSION,
        ]) ?? throw new SourceError("QuickBooks Online company {$this->realmId} answered nothing to a query of {$entity}.");
        $result = $answer->requiredChild('QueryResponse');

        return is_array($result->raw()[$entity] ?? null) ? $result->list($entity) : [];
    }

    /** The company's home currency, for a transaction that names none. */
    private function homeCurrency(): ?string
    {
        if ($this->homeCurrency === null) {
            $preferences = $this->client->get('/preferences', ['minorversion' => self::MINOR_VERSION])?->child('Preferences');
            $this->homeCurrency = $preferences?->child('CurrencyPrefs')?->child('HomeCurrency')?->optionalString('value') ?? '';
        }

        return $this->homeCurrency === '' ? null : $this->homeCurrency;
    }

    private static function isCategory(Node $item): bool
    {
        return $item->optionalString('Type') === self::CATEGORY;
    }

    /**
     * @param list<Node> $records
     *
     * @return list<Node>
     */
    private static function identified(string $type, array $records): array
    {
        return array_map(static function (Node $record) use ($type): Node {
            $gid = Fields::id($type, $record->string('Id'));

            return new Node([...$record->raw(), 'id' => $gid], $gid);
        }, $records);
    }
}
