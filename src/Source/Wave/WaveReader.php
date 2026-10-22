<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wave;

use DateTimeImmutable;
use DateTimeZone;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RateLimit;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\Wave\Mapping\Fields;

/**
 * Reads one Wave business through Wave's public GraphQL API (gql.waveapps.com/graphql/public; Operations). The
 * business is one the connected user can open (`businesses`): the one whose id the operator entered, else the only
 * one.
 *
 * - Products are its products, then its archived ones (the list leaves archived products out unless asked).
 * - Customers and contacts are its customers; companies are the customers whose name is a business's, not the
 *   contact person's (Mapping\Fields::companyName).
 * - Orders are its invoices with their items, taxes and payments.
 *
 * Lists are read 45 a page (Wave's default page size), oldest first. Incremental runs ask for what changed since
 * the last run (`modifiedAtAfter`). A GraphQL error answer is a failed read; one Wave marks unauthenticated or
 * forbidden stops the run and holds scheduled runs. Wave's own guidance on request limits could not be read (its
 * help centre refuses automated reads); a third-party guide reports 60 requests a minute per token, so requests
 * are paced at one a second, and a 429 is retried with capped backoff, then pauses the run.
 */
final class WaveReader implements SourceReader
{
    public const API_BASE = 'https://gql.waveapps.com';
    public const API_PATH = '/graphql/public';
    public const PAGE_SIZE = 45;
    public const REQUESTS_PER_SECOND = 1.0;
    public const CHANGED_SINCE_FORMAT = 'Y-m-d\TH:i:s\Z';

    private const REJECTED_CODES = ['UNAUTHENTICATED', 'FORBIDDEN'];
    private const CURSOR_SEPARATOR = ':';
    private const ACTIVE = 'active';
    private const ARCHIVED = 'archived';
    private const KINDS = [Kind::Product, Kind::Customer, Kind::CrmCompany, Kind::Contact, Kind::Order];

    private ?string $businessId = null;

    public function __construct(private readonly RestClient $client, private readonly ?string $chosenBusiness) {}

    public static function connect(string $apiBase, RequestAuthorizer $authorizer, HttpTransport $transport, Sleeper $sleeper, ?string $businessId): self
    {
        $rateLimit = new RateLimit(requestsPerSecond: self::REQUESTS_PER_SECOND);

        return new self(new RestClient('Wave', rtrim($apiBase, '/'), $authorizer, $transport, $sleeper, $rateLimit), $businessId);
    }

    public function shop(): Node
    {
        throw new SourceError('Wave has no store settings to mirror.');
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        $changed = $since?->setTimezone(new DateTimeZone('UTC'))->format(self::CHANGED_SINCE_FORMAT);

        return match ($kind) {
            Kind::Product => $this->products($cursor, $changed),
            Kind::Customer => $this->customers($cursor, $changed, 'Customer', static fn(Node $customer): bool => true),
            Kind::CrmCompany => $this->customers($cursor, $changed, 'Company', static fn(Node $customer): bool => Fields::companyName($customer) !== null),
            Kind::Contact => $this->customers($cursor, $changed, 'Contact', static fn(Node $customer): bool => true),
            Kind::Order => $this->invoices($cursor, $changed),
            default => throw new SourceError("Wave has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        throw new SourceError("Wave records are read in runs; a single {$kind->value} ({$gid}) is not read on its own.");
    }

    public function isIncremental(Kind $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /** Products, then archived ones: the cursor is `active:<page>` or `archived:<page>`. */
    private function products(?string $cursor, ?string $changed): Page
    {
        [$list, $page] = array_pad(explode(self::CURSOR_SEPARATOR, $cursor ?? self::ACTIVE . self::CURSOR_SEPARATOR . '1', 2), 2, '1');
        $archived = $list === self::ARCHIVED;
        [$products, $more] = $this->list(Operations::PRODUCTS, 'products', (int) $page, ['isArchived' => $archived, 'modifiedAtAfter' => $changed]);
        $nodes = array_map(static fn(Node $product): Node => self::identified('Product', $product), $products);
        if ($more) {
            return new Page($nodes, true, $list . self::CURSOR_SEPARATOR . ((int) $page + 1));
        }

        return new Page($nodes, !$archived, $archived ? null : self::ARCHIVED . self::CURSOR_SEPARATOR . '1');
    }

    /** @param callable(Node): bool $keep */
    private function customers(?string $cursor, ?string $changed, string $type, callable $keep): Page
    {
        $page = (int) ($cursor ?? '1');
        [$customers, $more] = $this->list(Operations::CUSTOMERS, 'customers', $page, ['modifiedAtAfter' => $changed]);
        $nodes = array_map(static fn(Node $customer): Node => self::identified($type, $customer), array_values(array_filter($customers, $keep)));

        return new Page($nodes, $more, $more ? (string) ($page + 1) : null);
    }

    private function invoices(?string $cursor, ?string $changed): Page
    {
        $page = (int) ($cursor ?? '1');
        [$invoices, $more] = $this->list(Operations::INVOICES, 'invoices', $page, ['modifiedAtAfter' => $changed]);

        return new Page(array_map(static fn(Node $invoice): Node => self::identified('Invoice', $invoice), $invoices), $more, $more ? (string) ($page + 1) : null);
    }

    /**
     * One page of one of the business's lists, and whether there are more.
     *
     * @param array<string, mixed> $variables
     *
     * @return array{0: list<Node>, 1: bool}
     */
    private function list(string $operation, string $field, int $page, array $variables): array
    {
        $business = $this->request($operation, ['businessId' => $this->businessId(), 'page' => $page, 'pageSize' => self::PAGE_SIZE, ...$variables])
            ->child('business') ?? throw new SourceError('Wave has no such business, or it is not open to this connection.');
        $connection = $business->requiredChild($field);
        $nodes = array_map(static fn(Node $edge): Node => $edge->requiredChild('node'), $connection->list('edges'));
        $info = $connection->requiredChild('pageInfo');

        return [$nodes, $info->int('currentPage') < ($info->optionalInt('totalPages') ?? 1)];
    }

    /** The id of the business to mirror. */
    private function businessId(): string
    {
        if ($this->businessId !== null) {
            return $this->businessId;
        }
        $businesses = [];
        $page = 1;
        do {
            $connection = $this->request(Operations::BUSINESSES, ['page' => $page, 'pageSize' => self::PAGE_SIZE])->requiredChild('businesses');
            foreach ($connection->list('edges') as $edge) {
                $businesses[$edge->requiredChild('node')->string('id')] = $edge->requiredChild('node')->string('name');
            }
            $info = $connection->requiredChild('pageInfo');
            ++$page;
        } while ($info->int('currentPage') < ($info->optionalInt('totalPages') ?? 1));
        if ($this->chosenBusiness !== null && $this->chosenBusiness !== '') {
            return $this->businessId = isset($businesses[$this->chosenBusiness])
                ? $this->chosenBusiness
                : throw new SourceError("The Wave connection can't open business {$this->chosenBusiness}.");
        }
        if (count($businesses) !== 1) {
            throw new SourceError($businesses === []
                ? 'The Wave connection opens no business.'
                : 'The Wave connection opens several businesses: enter the id of the one to mirror (' . implode(', ', array_map(
                    static fn(string $id, string $name): string => "{$name}: {$id}",
                    array_keys($businesses),
                    $businesses,
                )) . ').');
        }

        return $this->businessId = (string) array_key_first($businesses);
    }

    /**
     * Sends one operation and returns its data; a GraphQL error is a failed read.
     *
     * @param array<string, mixed> $variables
     */
    private function request(string $operation, array $variables): Node
    {
        $answer = $this->client->query(self::API_PATH, ['operationName' => $operation, 'query' => Operations::document($operation), 'variables' => $variables])
            ?? throw new SourceError("Wave answered nothing to {$operation}.");
        $errors = is_array($answer->raw()['errors'] ?? null) ? $answer->list('errors') : [];
        foreach ($errors as $error) {
            if (in_array($error->child('extensions')?->optionalString('code'), self::REJECTED_CODES, true)) {
                throw new CredentialsRejected('Wave refused the connection; connect the Wave business again.');
            }
        }
        if ($errors !== []) {
            throw new SourceError("Wave refused {$operation}: " . implode('; ', array_map(static fn(Node $error): string => $error->optionalString('message') ?? 'error', $errors)));
        }

        return $answer->child('data') ?? throw new SourceError("Wave answered {$operation} without data.");
    }

    private static function identified(string $type, Node $record): Node
    {
        $gid = Fields::id($type, Fields::recordId($record->string('id')));

        return new Node([...$record->raw(), 'wave_id' => $record->string('id'), 'id' => $gid], $gid);
    }
}
