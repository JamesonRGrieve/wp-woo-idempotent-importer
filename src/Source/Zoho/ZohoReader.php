<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\Zoho\Mapping\Fields;

/**
 * Reads an org through the Zoho CRM API v8 (`<api domain>/crm/v8`, zoho.com/crm/developer/docs/api/v8): records
 * by module, 200 a page (`page` for the first 2,000, `page_token` beyond; the cursor is `page:<n>` or
 * `token:<token>`), each asked for every field the module's metadata lists (`/settings/fields`, read once per run).
 * A request may name at most 50 fields, so a module with more is read 50 at a time: the first 50 for the page,
 * the rest for the same records by `ids`. Every node carries its identity as `id` (`gid://zoho/<module>/<id>`), the
 * metadata of the org's own fields (`custom_fields`) and the names of the fields a user can set (`stored_fields`).
 *
 * - Contacts are Contacts, then unconverted Leads (`converted=false`; the cursor names which), each with the
 *   campaigns it is a member of (`campaign_ids`, from every campaign's Contacts and Leads related lists, read once
 *   per run).
 * - Notes are Notes, Calls and Events, each with the contact, lead or account it belongs to; one with none of
 *   those is left out.
 * - Tags are those of the Contacts and Leads modules; product categories the values of the products' category
 *   picklist.
 * - Sales orders are read one by one too (their ordered items come only with a single record), with the org's
 *   home currency; customers are the contacts they name.
 *
 * Nothing is incremental (unchanged records are skipped by hash). Zoho counts API credits per org per day and
 * limits concurrent calls, not calls per minute (zoho.com/crm/developer/docs/api/v8/api-limits.html): the reader
 * makes one call at a time, and a 429 is retried with backoff, then pauses the run.
 */
final class ZohoReader implements SourceReader
{
    public const API_PATH = '/crm/v8';
    public const PAGE_SIZE = 200;
    /** Get Records takes at most 50 field API names (zoho.com/crm/developer/docs/api/v8/get-records.html). */
    public const MAX_FIELDS = 50;
    /** The `page` parameter reaches the first 2,000 records; `page_token` goes beyond. */
    public const PAGE_LIMIT = 10;

    private const CURSOR_SEPARATOR = '|';
    private const PEOPLE = ['Contacts' => ['converted' => null], 'Leads' => ['converted' => 'false']];
    private const ACTIVITIES = ['Notes', 'Calls', 'Events'];
    private const OWNER_MODULES = ['Contacts' => 'Contact', 'Leads' => 'Lead', 'Accounts' => 'Account'];
    private const TYPES = [
        'Accounts' => 'Account', 'Campaigns' => 'Campaign', 'Contacts' => 'Contact', 'Leads' => 'Lead', 'Notes' => 'Note', 'Calls' => 'Call',
        'Events' => 'Event', 'Products' => 'Product', 'Sales_Orders' => 'SalesOrder',
    ];
    private const CATEGORY_FIELD = 'Product_Category';

    /** @var array<string, list<Node>> module → its field metadata */
    private array $fields = [];
    /** @var array<string, list<string>>|null record id → the ids of the campaigns it is a member of */
    private ?array $campaignMembers = null;
    private ?string $orgCurrency = null;

    public function __construct(private readonly RestClient $client) {}

    public static function connect(string $apiDomain, RequestAuthorizer $authorizer, HttpTransport $transport, Sleeper $sleeper): self
    {
        if (preg_match('#^https?://[^/]+$#', rtrim($apiDomain, '/')) !== 1) {
            throw new SourceError('Zoho CRM is not connected: connect the org (its API domain comes with the connection).');
        }

        return new self(new RestClient('Zoho CRM', rtrim($apiDomain, '/') . self::API_PATH, $authorizer, $transport, $sleeper));
    }

    public function shop(): Node
    {
        throw new SourceError('Zoho CRM has no store settings to mirror.');
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        return match ($kind) {
            Kind::Collection => new Page($this->categories(), false, null),
            Kind::Product => $this->records('Products', $cursor),
            Kind::Customer => $this->customers($cursor),
            Kind::CrmCompany => $this->records('Accounts', $cursor),
            Kind::CrmList => $this->records('Campaigns', $cursor),
            Kind::CrmTag => new Page($this->tags(), false, null),
            Kind::Contact => $this->people($cursor),
            Kind::CrmNote => $this->activities($cursor),
            Kind::Order => $this->orders($cursor),
            default => throw new SourceError("Zoho CRM has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        throw new SourceError("Zoho CRM records are read in runs; a single {$kind->value} ({$gid}) is not read on its own.");
    }

    public function isIncremental(Kind $kind): bool
    {
        return false;
    }

    /**
     * One page of a module's records with every field.
     *
     * @param array<string, scalar> $query
     */
    private function records(string $module, ?string $cursor, array $query = []): Page
    {
        $chunks = array_chunk($this->fieldNames($module), self::MAX_FIELDS - 1);
        $response = $this->client->get("/{$module}", [...$query, 'fields' => implode(',', ['id', ...($chunks[0] ?? [])]), 'per_page' => self::PAGE_SIZE, ...self::paging($cursor)]);
        $records = [];
        foreach ($response?->list('data') ?? [] as $record) {
            $records[$record->string('id')] = $record->raw();
        }
        foreach (array_slice($chunks, 1) as $chunk) {
            if ($records === []) {
                break;
            }
            $more = $this->client->get("/{$module}", ['ids' => implode(',', array_keys($records)), 'fields' => implode(',', ['id', ...$chunk])]);
            foreach ($more?->list('data') ?? [] as $record) {
                $records[$record->string('id')] = [...($records[$record->string('id')] ?? []), ...$record->raw()];
            }
        }
        $next = self::nextCursor($response);
        $stored = $this->storedNames($module);
        $custom = array_map(static fn(Node $field): array => $field->raw(), $this->customDefinitions($module));
        $nodes = [];
        foreach ($records as $id => $record) {
            $nodes[] = self::identified(self::TYPES[$module] ?? $module, (string) $id, [...$record, 'module' => $module, 'stored_fields' => $stored, 'custom_fields' => $custom]);
        }

        return new Page($nodes, $next !== null, $next);
    }

    /** Contacts, then unconverted leads, with their campaigns: the cursor is `<module index>|<module cursor>`. */
    private function people(?string $cursor): Page
    {
        return $this->sequence(array_keys(self::PEOPLE), $cursor, function (string $module, ?string $inner): Page {
            $query = array_filter(self::PEOPLE[$module], static fn(?string $value): bool => $value !== null);
            $page = $this->records($module, $inner, $query);
            $members = $this->campaignMembers();

            return new Page(
                array_map(static fn(Node $person): Node => new Node([...$person->raw(), 'campaign_ids' => $members[Identity::sourceId($person->id())] ?? []], $person->id()), $page->nodes),
                $page->hasNextPage,
                $page->endCursor,
            );
        });
    }

    /** Notes, calls and events, each with what it belongs to. */
    private function activities(?string $cursor): Page
    {
        return $this->sequence(self::ACTIVITIES, $cursor, function (string $module, ?string $inner): Page {
            $page = $this->records($module, $inner);
            $nodes = [];
            foreach ($page->nodes as $activity) {
                [$type, $id] = self::owner($activity);
                if ($type !== null && $id !== null) {
                    $nodes[] = new Node([...$activity->raw(), 'belongs_to_type' => $type, 'belongs_to_id' => $id], $activity->id());
                }
            }

            return new Page($nodes, $page->hasNextPage, $page->endCursor);
        });
    }

    /**
     * The contact, lead or account an activity belongs to: a note's parent, else a call's or event's contact
     * (`Who_Id`), else what it relates to (`What_Id`, of the module `$se_module` names).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function owner(Node $activity): array
    {
        $parent = $activity->child('Parent_Id');
        if ($parent !== null) {
            return [self::OWNER_MODULES[$parent->child('module')?->optionalString('api_name') ?? ''] ?? null, $parent->optionalString('id')];
        }
        $who = Fields::lookupId($activity, 'Who_Id');
        if ($who !== null) {
            return ['Contact', $who];
        }

        return [self::OWNER_MODULES[$activity->optionalString('$se_module') ?? ''] ?? null, Fields::lookupId($activity, 'What_Id')];
    }

    /** One page of sales orders, each read by itself for its ordered items. */
    private function orders(?string $cursor): Page
    {
        $page = $this->records('Sales_Orders', $cursor);
        $nodes = [];
        foreach ($page->nodes as $order) {
            $id = Identity::sourceId($order->id());
            $full = $this->client->get('/Sales_Orders/' . rawurlencode($id))?->list('data')[0] ?? null;
            $nodes[] = new Node([...$order->raw(), ...($full?->raw() ?? []), 'id' => $order->id(), 'org_currency' => $this->orgCurrency()], $order->id());
        }

        return new Page($nodes, $page->hasNextPage, $page->endCursor);
    }

    /** One page of sales orders' contacts, as customers. */
    private function customers(?string $cursor): Page
    {
        $orders = $this->client->get('/Sales_Orders', ['fields' => 'id,Contact_Name', 'per_page' => self::PAGE_SIZE, ...self::paging($cursor)]);
        $ids = [];
        foreach ($orders?->list('data') ?? [] as $order) {
            $contact = Fields::lookupId($order, 'Contact_Name');
            if ($contact !== null) {
                $ids[$contact] = $contact;
            }
        }
        $customers = [];
        if ($ids !== []) {
            $page = $this->records('Contacts', null, ['ids' => implode(',', array_values($ids))]);
            foreach ($page->nodes as $contact) {
                $customers[] = self::identified('Customer', Identity::sourceId($contact->id()), $contact->raw());
            }
        }
        $next = self::nextCursor($orders);

        return new Page($customers, $next !== null, $next);
    }

    /**
     * Several modules one after another.
     *
     * @param list<string> $modules
     * @param callable(string, ?string): Page $read
     */
    private function sequence(array $modules, ?string $cursor, callable $read): Page
    {
        [$index, $inner] = array_pad(explode(self::CURSOR_SEPARATOR, $cursor ?? '', 2), 2, '');
        $index = (int) $index;
        $module = $modules[$index] ?? null;
        if ($module === null) {
            return new Page([], false, null);
        }
        $page = $read($module, $inner === '' ? null : $inner);
        if ($page->hasNextPage) {
            return new Page($page->nodes, true, $index . self::CURSOR_SEPARATOR . $page->endCursor);
        }
        $more = isset($modules[$index + 1]);

        return new Page($page->nodes, $more, $more ? ($index + 1) . self::CURSOR_SEPARATOR : null);
    }

    /** @return array<string, list<string>> record id → the ids of the campaigns it is a member of */
    private function campaignMembers(): array
    {
        if ($this->campaignMembers !== null) {
            return $this->campaignMembers;
        }
        $members = [];
        $cursor = null;
        do {
            $campaigns = $this->client->get('/Campaigns', ['fields' => 'id', 'per_page' => self::PAGE_SIZE, ...self::paging($cursor)]);
            foreach ($campaigns?->list('data') ?? [] as $campaign) {
                foreach (['Contacts', 'Leads'] as $related) {
                    $page = 1;
                    do {
                        $response = $this->client->get('/Campaigns/' . rawurlencode($campaign->string('id')) . "/{$related}", ['fields' => 'id', 'per_page' => self::PAGE_SIZE, 'page' => $page]);
                        foreach ($response?->list('data') ?? [] as $member) {
                            $members[$member->string('id')][] = $campaign->string('id');
                        }
                        ++$page;
                    } while (($response?->child('info')?->raw()['more_records'] ?? false) === true);
                }
            }
            $cursor = self::nextCursor($campaigns);
        } while ($cursor !== null);

        return $this->campaignMembers = $members;
    }

    /** @return list<Node> the tags of contacts and leads */
    private function tags(): array
    {
        $tags = [];
        foreach (['Contacts', 'Leads'] as $module) {
            foreach ($this->client->get('/settings/tags', ['module' => $module])?->list('tags') ?? [] as $tag) {
                $tags[$tag->string('id')] = self::identified('Tag', $tag->string('id'), [...$tag->raw(), 'module' => $module]);
            }
        }

        return array_values($tags);
    }

    /** @return list<Node> the values of the products' category picklist */
    private function categories(): array
    {
        foreach ($this->metadata('Products') as $field) {
            if ($field->string('api_name') === self::CATEGORY_FIELD) {
                return array_map(
                    static fn(Node $value): Node => new Node([...$value->raw(), 'id' => Fields::categoryId($value->optionalString('actual_value') ?? $value->string('display_value'))]),
                    is_array($field->raw()['pick_list_values'] ?? null) ? $field->list('pick_list_values') : [],
                );
            }
        }

        return [];
    }

    /** @return list<Node> a module's field metadata */
    private function metadata(string $module): array
    {
        return $this->fields[$module] ??= $this->required('/settings/fields', ['module' => $module])->list('fields');
    }

    /** @return list<string> the API names of a module's fields (every one but the id, which is always asked for) */
    private function fieldNames(string $module): array
    {
        return array_values(array_filter(array_map(static fn(Node $field): string => $field->string('api_name'), $this->metadata($module)), static fn(string $name): bool => $name !== 'id'));
    }

    /** @return list<string> the fields of a module a user can set */
    private function storedNames(string $module): array
    {
        return array_values(array_map(
            static fn(Node $field): string => $field->string('api_name'),
            array_filter($this->metadata($module), static fn(Node $field): bool => ($field->raw()['field_read_only'] ?? false) !== true && ($field->raw()['read_only'] ?? false) !== true),
        ));
    }

    /** @return list<Node> the org's own data fields of a module */
    private function customDefinitions(string $module): array
    {
        return array_values(array_filter($this->metadata($module), static fn(Node $field): bool => ($field->raw()['custom_field'] ?? false) === true && ($field->raw()['field_read_only'] ?? false) !== true));
    }

    /** The org's home currency, for orders in an org without multiple currencies. */
    private function orgCurrency(): string
    {
        $org = $this->orgCurrency === null ? $this->required('/org')->list('org')[0] ?? null : null;

        return $this->orgCurrency ??= $org?->string('iso_code') ?? throw new SourceError('Zoho CRM has no organization record.');
    }

    /**
     * The cursor of the page after a response's: its `next_page_token` when Zoho gives one, else the next page
     * number while `page` still reaches; null on the last page.
     */
    private static function nextCursor(?Node $response): ?string
    {
        $info = $response?->child('info');
        if ($info === null || ($info->raw()['more_records'] ?? false) !== true) {
            return null;
        }
        $token = $info->optionalString('next_page_token');
        $page = $info->optionalInt('page') ?? 1;

        return $token !== null ? "token:{$token}" : ($page < self::PAGE_LIMIT ? 'page:' . ($page + 1) : null);
    }

    /** @return array<string, scalar> */
    private static function paging(?string $cursor): array
    {
        [$position, $value] = array_pad(explode(':', $cursor ?? '', 2), 2, '');

        return $position === 'token' ? ['page_token' => $value] : ['page' => $position === 'page' ? (int) $value : 1];
    }

    /** @param array<string, scalar> $query */
    private function required(string $path, array $query = []): Node
    {
        return $this->client->get($path, $query) ?? throw new SourceError("Zoho CRM has no {$path} for this org.");
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, string $id, array $raw): Node
    {
        $gid = Fields::id($type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
