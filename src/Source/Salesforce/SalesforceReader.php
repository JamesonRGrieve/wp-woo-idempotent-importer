<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RateLimit;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\Salesforce\Mapping\Fields;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;

/**
 * Reads an org through the Salesforce REST API (`<instance>/services/data/v62.0`): records by SOQL through the
 * Query resource, which pages by `nextRecordsUrl` (the cursor), each object asked for every field its describe
 * lists (`/sobjects/<object>/describe`, read once per run; compound address and location fields are read as
 * their parts). Every node carries its identity as `id` (`gid://salesforce/<object>/<id>`), the describes of the
 * org's own (custom) data fields (`custom_fields`) and the names of the fields a user can set (`stored_fields`).
 *
 * - Contacts are Contacts, then unconverted Leads (the cursor names which), each with the campaigns it is a
 *   member of (`campaign_ids`) and its topics (`topic_ids`), from all campaign members and topic assignments,
 *   read once per run.
 * - Notes are logged calls and emails (tasks with subtype Call, Email or ListEmail), events and notes, each with
 *   the contact, lead or account it belongs to (`owner_type`, `owner_id`); one with none of those is left out.
 * - Products arrive with their standard price book entry; orders with their items and the org's default
 *   currency; customers are the contacts orders are billed to.
 *
 * Nothing is incremental (every run reads in full; unchanged records are skipped by hash). Salesforce limits API
 * requests per org per rolling 24 hours rather than per second, and answers a spent allowance with a 403
 * `REQUEST_LIMIT_EXCEEDED`, which is treated as rate limiting (the run pauses) rather than rejected credentials.
 */
final class SalesforceReader implements SourceReader
{
    public const API_PATH = '/services/data/v62.0';
    public const LIMIT_ERROR = 'REQUEST_LIMIT_EXCEEDED';

    private const HTTP_FORBIDDEN = 403;
    private const CURSOR_SEPARATOR = '|';
    /** Field types that are compounds of other fields (read through their parts) or binary. */
    private const UNSELECTED_TYPES = ['address', 'location', 'base64'];
    /** The activity objects read as notes, in order, with the condition each is read under. */
    private const ACTIVITIES = ['Task' => "TaskSubtype IN ('Call', 'Email', 'ListEmail')", 'Event' => '', 'Note' => ''];
    /** The person objects read as contacts, in order, with the condition each is read under. */
    private const PEOPLE = ['Contact' => '', 'Lead' => 'IsConverted = false'];

    /** @var array<string, array{all: list<string>, stored: list<string>, custom: list<array<array-key, mixed>>}> */
    private array $describes = [];
    /** @var array<string, array<string, list<string>>> membership kind → record id → the ids it belongs to */
    private array $memberships = [];
    private ?string $orgCurrency = null;

    public function __construct(private readonly RestClient $client) {}

    public static function connect(string $instanceUrl, RequestAuthorizer $authorizer, HttpTransport $transport, Sleeper $sleeper): self
    {
        if (preg_match('#^https?://[^/]+$#', rtrim($instanceUrl, '/')) !== 1) {
            throw new SourceError('Salesforce is not configured: connect the org (its instance URL comes with the connection).');
        }
        $limited = static fn(HttpResponse $response): bool => $response->status === self::HTTP_FORBIDDEN && str_contains($response->body, self::LIMIT_ERROR);

        return new self(new RestClient('Salesforce', rtrim($instanceUrl, '/'), $authorizer, $transport, $sleeper, new RateLimit(), $limited));
    }

    public function shop(): Node
    {
        throw new SourceError('Salesforce has no store settings to mirror.');
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        return match ($kind) {
            Kind::CrmCompany => $this->records('Account', '', $cursor),
            Kind::CrmList => $this->records('Campaign', '', $cursor),
            Kind::CrmTag => $this->records('Topic', '', $cursor),
            Kind::Contact => $this->sequence(self::PEOPLE, $cursor, $this->person(...)),
            Kind::CrmNote => $this->sequence(self::ACTIVITIES, $cursor, $this->activity(...)),
            Kind::Product => $this->records('Product2', '', $cursor, ', (SELECT UnitPrice FROM PricebookEntries WHERE Pricebook2.IsStandard = true)'),
            Kind::Customer => $this->records('Contact', 'Id IN (SELECT BillToContactId FROM Order)', $cursor, '', 'Customer'),
            Kind::Order => $this->records(
                'Order',
                '',
                $cursor,
                ', (SELECT Id, Product2Id, Product2.Name, Quantity, UnitPrice, TotalPrice, Description FROM OrderItems)',
                null,
                fn(Node $order): Node => new Node([...$order->raw(), 'org_currency' => $this->orgCurrency()]),
            ),
            default => throw new SourceError("Salesforce has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        throw new SourceError("Salesforce records are read in runs; a single {$kind->value} ({$gid}) is not read on its own.");
    }

    public function isIncremental(Kind $kind): bool
    {
        return false;
    }

    /**
     * One page of an object's records, each with its identity and its describe's field lists.
     *
     * @param callable(Node): ?Node|null $assemble
     */
    private function records(string $object, string $condition, ?string $cursor, string $subqueries = '', ?string $type = null, ?callable $assemble = null): Page
    {
        $describe = $this->describe($object);
        $response = $cursor === null || $cursor === ''
            ? $this->required(self::API_PATH . '/query', ['q' => 'SELECT ' . implode(', ', $describe['all']) . $subqueries . " FROM {$object}" . ($condition === '' ? '' : " WHERE {$condition}") . ' ORDER BY Id'])
            : $this->required($cursor);
        $nodes = [];
        foreach ($response->list('records') as $record) {
            $node = new Node([...$record->raw(), 'stored_fields' => $describe['stored'], 'custom_fields' => $describe['custom']]);
            $node = $assemble === null ? $node : $assemble($node);
            if ($node !== null) {
                $nodes[] = self::identified($type ?? $object, $record->string('Id'), $node->raw());
            }
        }
        $next = ($response->raw()['done'] ?? true) === true ? null : $response->optionalString('nextRecordsUrl');

        return new Page($nodes, $next !== null, $next);
    }

    /**
     * Several objects one after another: the cursor is `<object index>|<next records URL>`.
     *
     * @param array<string, string> $objects object → its condition
     * @param callable(Node): ?Node $assemble
     */
    private function sequence(array $objects, ?string $cursor, callable $assemble): Page
    {
        [$index, $next] = array_pad(explode(self::CURSOR_SEPARATOR, $cursor ?? '', 2), 2, '');
        $names = array_keys($objects);
        $index = (int) $index;
        $object = $names[$index] ?? null;
        if ($object === null) {
            return new Page([], false, null);
        }
        $page = $this->records($object, $objects[$object], $next === '' ? null : $next, '', null, $assemble);
        if ($page->hasNextPage) {
            return new Page($page->nodes, true, $index . self::CURSOR_SEPARATOR . $page->endCursor);
        }
        $more = isset($names[$index + 1]);

        return new Page($page->nodes, $more, $more ? ($index + 1) . self::CURSOR_SEPARATOR : null);
    }

    /** A contact or lead with its campaigns and topics. */
    private function person(Node $record): Node
    {
        $id = $record->string('Id');

        return new Node([
            ...$record->raw(),
            'campaign_ids' => $this->members('CampaignMember')[$id] ?? [],
            'topic_ids' => $this->members('TopicAssignment')[$id] ?? [],
        ]);
    }

    /** An activity with the record it belongs to, or null when it belongs to no contact, lead or account. */
    private function activity(Node $record): ?Node
    {
        foreach (['WhoId', 'ParentId', 'WhatId'] as $field) {
            $ownerId = $record->optionalString($field);
            $ownerType = Fields::ownerType($ownerId);
            if ($ownerId !== null && $ownerType !== null) {
                return new Node([...$record->raw(), 'owner_type' => $ownerType, 'owner_id' => $ownerId]);
            }
        }

        return null;
    }

    /**
     * Every campaign membership or topic assignment of contacts and leads, by member.
     *
     * @return array<string, list<string>>
     */
    private function members(string $object): array
    {
        if (isset($this->memberships[$object])) {
            return $this->memberships[$object];
        }
        $soql = $object === 'CampaignMember'
            ? 'SELECT CampaignId, ContactId, LeadId FROM CampaignMember ORDER BY Id'
            : "SELECT TopicId, EntityId FROM TopicAssignment WHERE EntityType IN ('Contact', 'Lead') ORDER BY Id";
        $members = [];
        $response = $this->required(self::API_PATH . '/query', ['q' => $soql]);
        while (true) {
            foreach ($response->list('records') as $row) {
                $group = $row->optionalString('CampaignId') ?? $row->string('TopicId');
                foreach (['ContactId', 'LeadId', 'EntityId'] as $field) {
                    $member = $row->optionalString($field);
                    if ($member !== null) {
                        $members[$member][] = $group;
                    }
                }
            }
            $next = ($response->raw()['done'] ?? true) === true ? null : $response->optionalString('nextRecordsUrl');
            if ($next === null) {
                break;
            }
            $response = $this->required($next);
        }

        return $this->memberships[$object] = $members;
    }

    /**
     * An object's fields: every selectable one, the ones a user can set, and the describes of the org's own data
     * fields.
     *
     * @return array{all: list<string>, stored: list<string>, custom: list<array<array-key, mixed>>}
     */
    private function describe(string $object): array
    {
        if (!isset($this->describes[$object])) {
            $all = [];
            $stored = [];
            $custom = [];
            foreach ($this->required(self::API_PATH . "/sobjects/{$object}/describe")->list('fields') as $field) {
                $raw = $field->raw();
                if (in_array($field->string('type'), self::UNSELECTED_TYPES, true)) {
                    continue;
                }
                $all[] = $field->string('name');
                if (($raw['calculated'] ?? false) === true || (($raw['updateable'] ?? false) !== true && ($raw['createable'] ?? false) !== true)) {
                    continue;
                }
                $stored[] = $field->string('name');
                if (($raw['custom'] ?? false) === true) {
                    $custom[] = $raw;
                }
            }
            $this->describes[$object] = ['all' => $all, 'stored' => $stored, 'custom' => $custom];
        }

        return $this->describes[$object];
    }

    /** The org's default currency, for orders in an org without multiple currencies. */
    private function orgCurrency(): string
    {
        $organization = $this->orgCurrency === null ? $this->required(self::API_PATH . '/query', ['q' => 'SELECT DefaultCurrencyIsoCode FROM Organization'])->list('records')[0] ?? null : null;

        return $this->orgCurrency ??= $organization?->string('DefaultCurrencyIsoCode') ?? throw new SourceError('Salesforce has no organization record.');
    }

    /** @param array<string, scalar> $query */
    private function required(string $path, array $query = []): Node
    {
        return $this->client->get($path, $query) ?? throw new SourceError("Salesforce has no {$path} for this org.");
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, string $id, array $raw): Node
    {
        $gid = Fields::id($type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
