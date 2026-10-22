<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\FreshBooks;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RateLimit;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\FreshBooks\Mapping\Fields;
use IdempotentImporter\Source\FreshBooks\Mapping\ProductMapper;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;

/**
 * Reads one FreshBooks business through its API (api.freshbooks.com, www.freshbooks.com/api). The business is one
 * of those the connected login is a member of (`/auth/api/v1/users/me`): the one whose account id the operator
 * entered, else the only one. Its account id addresses the accounting API (`/accounting/account/<account id>/…`)
 * and its business id the services API (`/comments/business/<business id>/…`).
 *
 * - Products are items, then services, each with its rate (read one by one).
 * - Customers and contacts are clients; companies are the organizations clients name, gathered from every client.
 * - Orders are invoices with their lines (`include[]=lines`), the payments on each (every payment is read once
 *   per run) and the client they are to.
 *
 * Lists are read 100 a page (`per_page`, silently capped at 100: www.freshbooks.com/api/parameters). Deleted
 * records (`vis_state` 1) are left out, so a full pass retires them. Nothing is incremental (unchanged records are
 * skipped by hash). FreshBooks puts no limit on requests a day but rate limits "too many calls within a short
 * period" (www.freshbooks.com/api/limits) without saying how many: requests are paced at two a second, a 429 is
 * retried with capped backoff, then pauses the run.
 */
final class FreshBooksReader implements SourceReader
{
    public const API_BASE = 'https://api.freshbooks.com';
    public const PER_PAGE = 100;
    public const REQUESTS_PER_SECOND = 2.0;
    public const IDENTITY_PATH = '/auth/api/v1/users/me';

    private const DELETED = 1;
    private const CURSOR_SEPARATOR = ':';
    private const ITEMS = 'items';
    private const SERVICES = 'services';
    private const ORGANIZATION_KEY_LENGTH = 16;

    /** @var array{account: string, business: int}|null */
    private ?array $business = null;
    /** @var list<Node>|null */
    private ?array $clients = null;
    /** @var array<int, list<array<array-key, mixed>>>|null invoice id → its payments, oldest first */
    private ?array $payments = null;

    public function __construct(private readonly RestClient $client, private readonly ?string $accountId) {}

    public static function connect(string $apiBase, RequestAuthorizer $authorizer, HttpTransport $transport, Sleeper $sleeper, ?string $accountId): self
    {
        $rateLimit = new RateLimit(requestsPerSecond: self::REQUESTS_PER_SECOND);

        return new self(new RestClient('FreshBooks', rtrim($apiBase, '/'), $authorizer, $transport, $sleeper, $rateLimit), $accountId);
    }

    public function shop(): Node
    {
        throw new SourceError('FreshBooks has no store settings to mirror.');
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        return match ($kind) {
            Kind::Product => $this->products($cursor),
            Kind::Customer => $this->clientPage($cursor, 'Customer'),
            Kind::CrmCompany => new Page($this->organizations(), false, null),
            Kind::Contact => $this->clientPage($cursor, 'Contact'),
            Kind::Order => $this->invoices($cursor),
            default => throw new SourceError("FreshBooks has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        throw new SourceError("FreshBooks records are read in runs; a single {$kind->value} ({$gid}) is not read on its own.");
    }

    public function isIncremental(Kind $kind): bool
    {
        return false;
    }

    /** Items, then services: the cursor is `items:<page>` or `services:<page>`. */
    private function products(?string $cursor): Page
    {
        [$list, $page] = array_pad(explode(self::CURSOR_SEPARATOR, $cursor ?? self::ITEMS . self::CURSOR_SEPARATOR . '1', 2), 2, '1');
        if ($list === self::ITEMS) {
            [$items, $pages] = $this->accounting('/items/items', self::ITEMS, (int) $page);
            $nodes = array_map(static fn(Node $item): Node => self::identified('Item', $item->int('id'), [...$item->raw(), 'record_type' => 'Item']), $items);
            $next = (int) $page < $pages ? self::ITEMS . self::CURSOR_SEPARATOR . ((int) $page + 1) : self::SERVICES . self::CURSOR_SEPARATOR . '1';

            return new Page($nodes, true, $next);
        }
        $business = $this->business()['business'];
        $response = $this->required("/comments/business/{$business}/services", ['page' => (int) $page, 'per_page' => self::PER_PAGE]);
        $nodes = [];
        foreach (self::live($response->list(self::SERVICES)) as $service) {
            $rate = $this->client->get("/comments/business/{$business}/service/{$service->int('id')}/rate")?->child('service_rate');
            $nodes[] = self::identified(ProductMapper::SERVICE, $service->int('id'), [...$service->raw(), 'record_type' => ProductMapper::SERVICE, 'rate' => $rate?->raw()]);
        }
        $pages = $response->child('meta')?->optionalInt('pages') ?? 1;
        $more = (int) $page < $pages;

        return new Page($nodes, $more, $more ? self::SERVICES . self::CURSOR_SEPARATOR . ((int) $page + 1) : null);
    }

    /** One page of clients, as customers or contacts (each with its organization's identity). */
    private function clientPage(?string $cursor, string $type): Page
    {
        $page = (int) ($cursor ?? '1');
        [$clients, $pages] = $this->accounting('/users/clients', 'clients', $page);
        $nodes = array_map(static function (Node $client) use ($type): Node {
            $organization = Fields::text($client, 'organization');

            return self::identified($type, $client->int('id'), [...$client->raw(), 'organization_gid' => $organization === null ? null : self::organizationId($organization)]);
        }, $clients);

        return new Page($nodes, $page < $pages, $page < $pages ? (string) ($page + 1) : null);
    }

    /**
     * The organizations clients name, each the first such client's details with the ids of all of them.
     *
     * @return list<Node>
     */
    private function organizations(): array
    {
        $first = [];
        $members = [];
        foreach ($this->allClients() as $client) {
            $name = Fields::text($client, 'organization');
            if ($name === null) {
                continue;
            }
            $gid = self::organizationId($name);
            $first[$gid] ??= [...$client->raw(), 'organization' => $name];
            $members[$gid][] = $client->int('id');
        }
        $organizations = [];
        foreach ($first as $gid => $raw) {
            $organizations[] = new Node([...$raw, 'id' => $gid, 'client_ids' => $members[$gid] ?? []], $gid);
        }

        return $organizations;
    }

    /** One page of invoices with their lines and payments. */
    private function invoices(?string $cursor): Page
    {
        $page = (int) ($cursor ?? '1');
        [$invoices, $pages] = $this->accounting('/invoices/invoices', 'invoices', $page, ['include[]' => 'lines']);
        $payments = $this->payments();
        $clients = [];
        foreach ($this->allClients() as $client) {
            $clients[$client->int('id')] = $client->raw();
        }
        $nodes = array_map(
            static fn(Node $invoice): Node => self::identified('Invoice', $invoice->int('id'), [
                ...$invoice->raw(),
                'payments' => $payments[$invoice->int('id')] ?? [],
                'client' => $clients[$invoice->optionalInt('customerid') ?? 0] ?? null,
            ]),
            $invoices,
        );

        return new Page($nodes, $page < $pages, $page < $pages ? (string) ($page + 1) : null);
    }

    /** @return list<Node> every live client, read once per run */
    private function allClients(): array
    {
        if ($this->clients !== null) {
            return $this->clients;
        }
        $clients = [];
        $page = 1;
        do {
            [$records, $pages] = $this->accounting('/users/clients', 'clients', $page);
            $clients = [...$clients, ...$records];
            ++$page;
        } while ($page <= $pages);

        return $this->clients = $clients;
    }

    /** @return array<int, list<array<array-key, mixed>>> invoice id → its live payments, oldest first */
    private function payments(): array
    {
        if ($this->payments !== null) {
            return $this->payments;
        }
        $byInvoice = [];
        $page = 1;
        do {
            [$records, $pages] = $this->accounting('/payments/payments', 'payments', $page);
            foreach ($records as $payment) {
                $invoice = $payment->optionalInt('invoiceid');
                if ($invoice !== null) {
                    $byInvoice[$invoice][] = $payment->raw();
                }
            }
            ++$page;
        } while ($page <= $pages);
        foreach ($byInvoice as &$payments) {
            usort($payments, static fn(array $a, array $b): int => [$a['date'] ?? '', $a['id'] ?? 0] <=> [$b['date'] ?? '', $b['id'] ?? 0]);
        }
        unset($payments);

        return $this->payments = $byInvoice;
    }

    /**
     * One page of an accounting list's live records, and how many pages it has.
     *
     * @param array<string, scalar> $query
     *
     * @return array{0: list<Node>, 1: int}
     */
    private function accounting(string $path, string $key, int $page, array $query = []): array
    {
        $account = $this->business()['account'];
        $result = $this->required("/accounting/account/{$account}{$path}", [...$query, 'page' => $page, 'per_page' => self::PER_PAGE])
            ->requiredChild('response')->requiredChild('result');

        return [self::live($result->list($key)), $result->optionalInt('pages') ?? 1];
    }

    /** @return array{account: string, business: int} the business to mirror */
    private function business(): array
    {
        if ($this->business !== null) {
            return $this->business;
        }
        $businesses = [];
        foreach ($this->required(self::IDENTITY_PATH)->requiredChild('response')->list('business_memberships') as $membership) {
            $business = $membership->requiredChild('business');
            $businesses[$business->string('account_id')] = ['account' => $business->string('account_id'), 'business' => $business->int('id')];
        }
        if ($this->accountId !== null && $this->accountId !== '') {
            return $this->business = $businesses[$this->accountId] ?? throw new SourceError("The FreshBooks login is not a member of account {$this->accountId}.");
        }
        if (count($businesses) !== 1) {
            throw new SourceError($businesses === []
                ? 'The FreshBooks login is a member of no business.'
                : 'The FreshBooks login is a member of several businesses: enter the account id to mirror (' . implode(', ', array_keys($businesses)) . ').');
        }

        return $this->business = array_values($businesses)[0];
    }

    /**
     * @param list<Node> $records
     *
     * @return list<Node> those not deleted
     */
    private static function live(array $records): array
    {
        return array_values(array_filter($records, static fn(Node $record): bool => $record->optionalInt('vis_state') !== self::DELETED));
    }

    /** An organization's identity: its name, case and spacing aside. */
    private static function organizationId(string $name): string
    {
        $key = strtolower((string) preg_replace('/\s+/', ' ', trim($name)));

        return Fields::id('Organization', substr(hash('sha256', $key), 0, self::ORGANIZATION_KEY_LENGTH));
    }

    /** @param array<string, scalar> $query */
    private function required(string $path, array $query = []): Node
    {
        return $this->client->get($path, $query) ?? throw new SourceError("FreshBooks has no {$path}.");
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, int $id, array $raw): Node
    {
        $gid = Fields::id($type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
