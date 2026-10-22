<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\HubSpot;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RateLimit;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\HubSpot\Mapping\Fields;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;

/**
 * Reads a HubSpot account through the CRM API v3 (developers.hubspot.com/docs/api-reference): objects listed at
 * `/crm/v3/objects/<type>` a hundred at a time (`limit`, `after`), each asked for every property the account can
 * set (from the Properties API, `/crm/v3/properties/<type>`) and the associations its mapper needs. Every node
 * carries its identity as `id` (`gid://hubspot/<Type>/<id>`) and, from the property definitions, the names of
 * the properties the account can set (`stored_properties`) and the definitions of its own (`custom_properties`).
 *
 * - Contacts arrive with their companies and the ids of the contact lists they belong to (`list_ids`, from every
 *   contact list's memberships, read once per run).
 * - Notes, calls, emails and meetings are read type by type (the cursor is `<type index>:<after>`), each as one
 *   node per associated contact and per associated company, identified `<id>-contact-<contact id>` or
 *   `<id>-company-<company id>`.
 * - Orders arrive with their line items (batch read) and contacts; customers are the contacts associated with
 *   orders, page by page of orders.
 * - Boards are the deal pipelines with their stages (`/crm/v3/pipelines/deals`), and the account's task list, whose
 *   stages are the options of the task status property. Deals (object type `0-3`) and tasks arrive with their
 *   contacts and companies and their owner (`owner`: the HubSpot user, from the Owners API, read once per run,
 *   active and archived).
 *
 * Nothing is incremental: the list endpoints have no changed-since filter (only the Search API does, with its own
 * lower limits and a 10,000-result cap), so every run reads in full and unchanged objects are skipped by hash.
 */
final class HubSpotReader implements SourceReader
{
    public const API_BASE = 'https://api.hubapi.com';
    public const PAGE_SIZE = 100;
    public const LIST_PAGE_SIZE = 100;
    public const MEMBERSHIP_PAGE_SIZE = 250;
    /**
     * A privately distributed app may make 100 requests every 10 seconds on Free and Starter accounts (190 on
     * Professional and Enterprise), an OAuth app 110 per installing account, and every response says what is
     * left of the window in `X-HubSpot-RateLimit-Remaining`; past it every request is a 429
     * (developers.hubspot.com/docs/developer-tooling/platform/usage-guidelines). Requests are paced at the lowest
     * of those rates, and once the window is down to its last request the next waits for it to pass.
     */
    public const REQUESTS_PER_WINDOW = 100;
    public const WINDOW_SECONDS = 10;
    public const REMAINING_HEADER = 'X-HubSpot-RateLimit-Remaining';
    /** The contact object's type id, which the Lists API names lists by. */
    public const CONTACT_OBJECT_TYPE = '0-1';
    /** The task status property, whose options are the task board's stages. */
    public const TASK_STATUS = 'hs_task_status';
    /** The property naming the HubSpot user a record belongs to. */
    public const OWNER_PROPERTY = 'hubspot_owner_id';
    public const OWNERS_PAGE_SIZE = 100;
    /** The activity objects read as notes, in order. */
    public const ENGAGEMENT_TYPES = ['notes', 'calls', 'emails', 'meetings'];

    private const CURSOR_SEPARATOR = ':';
    /** Identity type of each object type read. */
    private const TYPES = [
        'contacts' => 'Contact', 'companies' => 'Company', 'notes' => 'Note', 'calls' => 'Call', 'emails' => 'Email',
        'meetings' => 'Meeting', 'products' => 'Product', 'orders' => 'Order', 'line_items' => 'LineItem',
        'deals' => 'Deal', 'tasks' => 'Task',
    ];
    /** The object path of a type whose published document names it by its object type id. */
    private const OBJECT_PATHS = ['deals' => '0-3'];
    /** The types that arrive with their owner. */
    private const OWNED = ['deals', 'tasks'];

    /** @var array<string, array{all: list<string>, stored: list<string>, custom: list<array<array-key, mixed>>, definitions: array<string, array<array-key, mixed>>}> object type → its properties */
    private array $properties = [];
    /** @var array<string, array<array-key, mixed>>|null owner id → the owner */
    private ?array $owners = null;
    /** @var array<string, list<string>>|null contact id → the ids of its lists */
    private ?array $memberships = null;

    public function __construct(private readonly RestClient $client) {}

    public static function connect(string $apiBase, RequestAuthorizer $authorizer, HttpTransport $transport, Sleeper $sleeper): self
    {
        $rateLimit = new RateLimit(
            requestsPerSecond: RateLimit::perPeriod(self::REQUESTS_PER_WINDOW, self::WINDOW_SECONDS),
            cooldownSeconds: self::WINDOW_SECONDS,
            remainingHeader: self::REMAINING_HEADER,
            windowSeconds: self::WINDOW_SECONDS,
        );

        return new self(new RestClient('HubSpot', rtrim($apiBase, '/'), $authorizer, $transport, $sleeper, $rateLimit));
    }

    public function shop(): Node
    {
        throw new SourceError('HubSpot has no store settings to mirror.');
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        return match ($kind) {
            Kind::CrmCompany => $this->objects('companies', $cursor, []),
            Kind::CrmList => $this->lists($cursor),
            Kind::Contact => $this->objects('contacts', $cursor, ['companies']),
            Kind::CrmNote => $this->engagements($cursor),
            Kind::Product => $this->objects('products', $cursor, []),
            Kind::Customer => $this->customers($cursor),
            Kind::Order => $this->objects('orders', $cursor, ['contacts', 'line_items']),
            Kind::CrmPipeline => new Page($this->boards(), false, null),
            Kind::CrmDeal => $this->objects('deals', $cursor, ['contacts', 'companies']),
            Kind::CrmTask => $this->objects('tasks', $cursor, ['contacts', 'companies']),
            default => throw new SourceError("HubSpot has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        $type = match ($kind) {
            Kind::CrmCompany => 'companies',
            Kind::Contact => 'contacts',
            Kind::Product => 'products',
            Kind::Order => 'orders',
            default => throw new SourceError("HubSpot can't read a single {$kind->value}."),
        };
        $associations = ['contacts' => ['companies'], 'orders' => ['contacts', 'line_items']][$type] ?? [];
        $object = $this->client->get("/crm/v3/objects/{$type}/" . rawurlencode(Identity::sourceId($gid)), $this->query($type, $associations));

        return $object === null ? null : $this->assemble($type, $object);
    }

    public function isIncremental(Kind $kind): bool
    {
        return false;
    }

    /**
     * One page of an object type.
     *
     * @param list<string> $associations
     */
    private function objects(string $type, ?string $cursor, array $associations): Page
    {
        $query = $this->query($type, $associations);
        if ($cursor !== null && $cursor !== '') {
            $query['after'] = $cursor;
        }
        $response = $this->required('/crm/v3/objects/' . (self::OBJECT_PATHS[$type] ?? $type), $query);
        $after = $response->child('paging')?->child('next')?->optionalString('after');

        return new Page(array_map(fn(Node $object): Node => $this->assemble($type, $object), $response->list('results')), $after !== null, $after);
    }

    /**
     * The query of a list or single read: every property the account can set, and the associations.
     *
     * @param list<string> $associations
     *
     * @return array<string, scalar>
     */
    private function query(string $type, array $associations): array
    {
        $query = ['limit' => self::PAGE_SIZE, 'properties' => implode(',', $this->properties($type)['all']), 'archived' => false];
        if ($associations !== []) {
            $query['associations'] = implode(',', $associations);
        }

        return $query;
    }

    /** An object with its identity, its type's property names and custom definitions, and (for a contact) its lists. */
    private function assemble(string $type, Node $object): Node
    {
        $properties = $this->properties($type);
        $raw = [...$object->raw(), 'stored_properties' => $properties['stored'], 'custom_properties' => $properties['custom']];
        if ($type === 'contacts') {
            $raw['list_ids'] = $this->memberships()[$object->id()] ?? [];
        }
        if ($type === 'orders') {
            $raw['line_items'] = $this->lineItems(Fields::associated($object, 'line_items'));
        }
        if (in_array($type, self::OWNED, true)) {
            $raw['owner'] = $this->owners()[Fields::property($object, self::OWNER_PROPERTY) ?? ''] ?? null;
        }

        return self::identified(self::TYPES[$type], $object->id(), $raw);
    }

    /**
     * An object type's property names: every one (what is asked for), those the account can set (neither
     * calculated nor read-only), and the definitions of the account's own among those.
     *
     * @return array{all: list<string>, stored: list<string>, custom: list<array<array-key, mixed>>, definitions: array<string, array<array-key, mixed>>}
     */
    private function properties(string $type): array
    {
        if (!isset($this->properties[$type])) {
            $all = [];
            $stored = [];
            $custom = [];
            $definitions = [];
            foreach ($this->required("/crm/v3/properties/{$type}")->list('results') as $definition) {
                $raw = $definition->raw();
                if (($raw['archived'] ?? false) === true) {
                    continue;
                }
                $all[] = $definition->string('name');
                $definitions[$definition->string('name')] = $raw;
                $readOnly = ($definition->child('modificationMetadata')?->raw()['readOnlyValue'] ?? false) === true;
                if (($raw['calculated'] ?? false) === true || $readOnly) {
                    continue;
                }
                $stored[] = $definition->string('name');
                if (($raw['hubspotDefined'] ?? false) !== true && ($raw['hidden'] ?? false) !== true) {
                    $custom[] = $raw;
                }
            }
            $this->properties[$type] = ['all' => $all, 'stored' => $stored, 'custom' => $custom, 'definitions' => $definitions];
        }

        return $this->properties[$type];
    }

    /**
     * The line items an order names, read in one batch.
     *
     * @param list<string> $ids
     *
     * @return list<array<array-key, mixed>>
     */
    private function lineItems(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $response = $this->client->query('/crm/v3/objects/line_items/batch/read', [
            'inputs' => array_map(static fn(string $id): array => ['id' => $id], $ids),
            'properties' => $this->properties('line_items')['all'],
        ]) ?? throw new SourceError('HubSpot could not read the line items of an order.');
        $items = [];
        foreach ($response->list('results') as $item) {
            $items[$item->id()] = self::identified('LineItem', $item->id(), [...$item->raw(), 'stored_properties' => $this->properties('line_items')['stored'], 'custom_properties' => []])->raw();
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($items[$id])) {
                $ordered[] = $items[$id];
            }
        }

        return $ordered;
    }

    /** One page of contact lists (offset paging). */
    private function lists(?string $cursor): Page
    {
        $offset = $cursor === null || $cursor === '' ? 0 : (int) $cursor;
        $response = $this->required('/crm/v3/lists/search', [], ['offset' => $offset, 'count' => self::LIST_PAGE_SIZE, 'objectTypeId' => self::CONTACT_OBJECT_TYPE, 'additionalProperties' => []]);
        $lists = array_values(array_filter($response->list('lists'), static fn(Node $list): bool => $list->string('objectTypeId') === self::CONTACT_OBJECT_TYPE));
        $more = ($response->raw()['hasMore'] ?? false) === true;

        return new Page(array_map(static fn(Node $list): Node => self::identified('List', $list->string('listId'), $list->raw()), $lists), $more, $more ? (string) $response->int('offset') : null);
    }

    /** @return array<string, list<string>> contact id → the ids of every contact list it is in */
    private function memberships(): array
    {
        if ($this->memberships !== null) {
            return $this->memberships;
        }
        $memberships = [];
        $cursor = null;
        do {
            $page = $this->lists($cursor);
            foreach ($page->nodes as $list) {
                $listId = Identity::sourceId($list->id());
                $after = null;
                do {
                    $query = ['limit' => self::MEMBERSHIP_PAGE_SIZE, ...($after === null ? [] : ['after' => $after])];
                    $response = $this->required('/crm/v3/lists/' . rawurlencode($listId) . '/memberships', $query);
                    foreach ($response->list('results') as $member) {
                        $memberships[$member->string('recordId')][] = $listId;
                    }
                    $after = $response->child('paging')?->child('next')?->optionalString('after');
                } while ($after !== null);
            }
            $cursor = $page->endCursor;
        } while ($page->hasNextPage);

        return $this->memberships = $memberships;
    }

    /** One page of activities: each as one node per associated contact and company. */
    private function engagements(?string $cursor): Page
    {
        [$index, $after] = array_pad(explode(self::CURSOR_SEPARATOR, $cursor ?? '', 2), 2, '');
        $index = (int) $index;
        $type = self::ENGAGEMENT_TYPES[$index] ?? null;
        if ($type === null) {
            return new Page([], false, null);
        }
        $page = $this->objects($type, $after === '' ? null : $after, ['contacts', 'companies']);
        $nodes = [];
        foreach ($page->nodes as $activity) {
            $id = Identity::sourceId($activity->id());
            foreach (['contact' => 'contacts', 'company' => 'companies'] as $owner => $association) {
                foreach (Fields::associated($activity, $association) as $ownerId) {
                    $nodes[] = self::identified(self::TYPES[$type], "{$id}-{$owner}-{$ownerId}", [...$activity->raw(), 'engagement_type' => $type, "{$owner}_id" => $ownerId]);
                }
            }
        }
        if ($page->hasNextPage) {
            return new Page($nodes, true, $index . self::CURSOR_SEPARATOR . $page->endCursor);
        }
        $more = isset(self::ENGAGEMENT_TYPES[$index + 1]);

        return new Page($nodes, $more, $more ? ($index + 1) . self::CURSOR_SEPARATOR : null);
    }

    /**
     * The deal pipelines, each with its stages, then the task board, whose stages are the task status options.
     *
     * @return list<Node>
     */
    private function boards(): array
    {
        $boards = array_map(
            static fn(Node $pipeline): Node => self::identified('Pipeline', $pipeline->string('id'), $pipeline->raw()),
            $this->required('/crm/v3/pipelines/deals')->list('results'),
        );
        $status = $this->properties('tasks')['definitions'][self::TASK_STATUS] ?? throw new SourceError('HubSpot defines no task status property.');
        $boards[] = self::identified('TaskBoard', 'tasks', ['status_property' => $status]);

        return $boards;
    }

    /** @return array<string, array<array-key, mixed>> owner id → the owner, active and archived, read once per run */
    private function owners(): array
    {
        if ($this->owners !== null) {
            return $this->owners;
        }
        $owners = [];
        foreach ([false, true] as $archived) {
            $after = null;
            do {
                $response = $this->required('/crm/v3/owners', ['limit' => self::OWNERS_PAGE_SIZE, 'archived' => $archived, ...($after === null ? [] : ['after' => $after])]);
                foreach ($response->list('results') as $owner) {
                    $owners[$owner->string('id')] ??= $owner->raw();
                }
                $after = $response->child('paging')?->child('next')?->optionalString('after');
            } while ($after !== null);
        }

        return $this->owners = $owners;
    }

    /** One page of orders' contacts, read in one batch, as customers. */
    private function customers(?string $cursor): Page
    {
        $query = ['limit' => self::PAGE_SIZE, 'properties' => 'hs_order_name', 'associations' => 'contacts', 'archived' => false, ...($cursor === null || $cursor === '' ? [] : ['after' => $cursor])];
        $orders = $this->required('/crm/v3/objects/orders', $query);
        $after = $orders->child('paging')?->child('next')?->optionalString('after');
        $ids = [];
        foreach ($orders->list('results') as $order) {
            foreach (Fields::associated($order, 'contacts') as $contactId) {
                $ids[$contactId] = $contactId;
            }
        }
        $customers = [];
        if ($ids !== []) {
            $response = $this->client->query('/crm/v3/objects/contacts/batch/read', [
                'inputs' => array_map(static fn(string $id): array => ['id' => $id], array_values($ids)),
                'properties' => $this->properties('contacts')['all'],
            ]) ?? throw new SourceError('HubSpot could not read the contacts of orders.');
            foreach ($response->list('results') as $contact) {
                $customers[] = self::identified('Customer', $contact->id(), [...$contact->raw(), 'stored_properties' => $this->properties('contacts')['stored'], 'custom_properties' => []]);
            }
        }

        return new Page($customers, $after !== null, $after);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed>|null $body a read-only query sent as a JSON POST
     */
    private function required(string $path, array $query = [], ?array $body = null): Node
    {
        $response = $body === null ? $this->client->get($path, $query) : $this->client->query($path, $body);

        return $response ?? throw new SourceError("HubSpot has no {$path} for this account.");
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, string $id, array $raw): Node
    {
        $gid = Fields::id($type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
