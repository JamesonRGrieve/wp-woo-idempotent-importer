<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RateLimit;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Page;
use IdempotentImporter\Source\Pipedrive\Mapping\Fields;
use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;

/**
 * Reads a Pipedrive company through its API (developers.pipedrive.com/docs/api/v1): persons, organizations,
 * products and activities from API v2 (`/api/v2/…`, paged by `cursor`), notes, filters, the person, organization
 * and product field definitions and the user's default currency from API v1 (`/api/v1/…`, paged by `start`).
 * Every node carries its identity as `id` (`gid://pipedrive/<Type>/<id>`).
 *
 * - Persons arrive with their marketing status, the person filters they match (`filter_ids`, from every person
 *   filter's persons, read once per run) and the account's person field definitions; organizations with theirs
 *   and their industry's name; products with their variations, the default currency and their fields.
 * - Labels are the options of the person label field; product categories the options of the product category
 *   field.
 * - Notes, then done activities, are read one after the other (the cursor names which), each as one node per
 *   person and organization it belongs to (`<id>-person-<person id>`, `<id>-org-<organization id>`). Activities
 *   still to do are tasks (planned).
 *
 * Persons, organizations, products and activities are incremental (`updated_since`); everything else is read in
 * full, and unchanged objects are skipped by hash.
 */
final class PipedriveReader implements SourceReader
{
    public const API_BASE = 'https://api.pipedrive.com';
    public const V1 = '/api/v1';
    public const V2 = '/api/v2';
    public const PAGE_SIZE = 100;
    public const V1_PAGE_SIZE = 500;
    /**
     * Pipedrive limits each token to a burst of requests per rolling 2-second window by plan (20 for an API token
     * on Lite, the lowest, 80 for an OAuth app), reporting what is left in `x-ratelimit-remaining` and when the
     * window resets in `x-ratelimit-reset` (seconds), and spends a daily token budget per company; a spent budget
     * is a 429 until the daily reset (pipedrive.readme.io/docs/core-api-concepts-rate-limiting). Requests are paced
     * at the lowest burst, ten a second.
     */
    public const REQUESTS_PER_WINDOW = 20;
    public const WINDOW_SECONDS = 2;
    public const REMAINING_HEADER = 'x-ratelimit-remaining';
    public const RESET_HEADER = 'x-ratelimit-reset';

    private const CURSOR_SEPARATOR = ':';
    private const LABEL_FIELDS = ['label_ids', 'label'];
    private const CATEGORY_FIELD = 'category';
    private const INDUSTRY_FIELD = 'industry';
    private const INCREMENTAL = [Kind::Contact, Kind::CrmCompany, Kind::Product];

    /** @var array<string, list<Node>> field kind → its field definitions */
    private array $fieldDefinitions = [];
    /** @var array<int, list<int>>|null person id → the ids of the filters it matches */
    private ?array $filterMembers = null;
    private ?string $currency = null;

    public function __construct(private readonly RestClient $client) {}

    public static function connect(string $apiBase, RequestAuthorizer $authorizer, HttpTransport $transport, Sleeper $sleeper): self
    {
        $rateLimit = new RateLimit(
            requestsPerSecond: RateLimit::perPeriod(self::REQUESTS_PER_WINDOW, self::WINDOW_SECONDS),
            remainingHeader: self::REMAINING_HEADER,
            resetHeader: self::RESET_HEADER,
            windowSeconds: self::WINDOW_SECONDS,
        );

        return new self(new RestClient('Pipedrive', rtrim($apiBase, '/'), $authorizer, $transport, $sleeper, $rateLimit));
    }

    public function shop(): Node
    {
        throw new SourceError('Pipedrive has no store settings to mirror.');
    }

    public function page(Kind $kind, ?string $cursor, ?DateTimeImmutable $since, string $filter): Page
    {
        $updated = $since === null ? [] : ['updated_since' => $since->format(DATE_RFC3339)];

        return match ($kind) {
            Kind::Collection => new Page($this->options('productFields', self::CATEGORY_FIELD, 'Category'), false, null),
            Kind::CrmCompany => $this->v2('/organizations', $cursor, $updated, $this->organization(...)),
            Kind::CrmList => new Page(array_map(static fn(Node $filter): Node => self::identified('Filter', $filter->int('id'), $filter->raw()), $this->filters()), false, null),
            Kind::CrmTag => new Page($this->options('personFields', self::LABEL_FIELDS, 'Label'), false, null),
            Kind::Contact => $this->v2('/persons', $cursor, [...$updated, 'include_fields' => 'marketing_status'], $this->person(...)),
            Kind::CrmNote => $this->notes($cursor),
            Kind::Product => $this->v2('/products', $cursor, $updated, $this->product(...)),
            default => throw new SourceError("Pipedrive has no {$kind->value}."),
        };
    }

    public function node(Kind $kind, string $gid): ?Node
    {
        $path = match ($kind) {
            Kind::Contact => '/persons/',
            Kind::CrmCompany => '/organizations/',
            Kind::Product => '/products/',
            default => throw new SourceError("Pipedrive can't read a single {$kind->value}."),
        };
        $response = $this->client->get(self::V2 . $path . rawurlencode(Identity::sourceId($gid)), $kind === Kind::Contact ? ['include_fields' => 'marketing_status'] : []);
        $item = $response?->child('data');
        if ($item === null) {
            return null;
        }

        return match ($kind) {
            Kind::Contact => $this->person($item),
            Kind::CrmCompany => $this->organization($item),
            default => $this->product($item),
        };
    }

    public function isIncremental(Kind $kind): bool
    {
        return in_array($kind, self::INCREMENTAL, true);
    }

    /**
     * One page of an API v2 list.
     *
     * @param array<string, scalar> $query
     * @param callable(Node): Node $assemble
     */
    private function v2(string $path, ?string $cursor, array $query, callable $assemble): Page
    {
        $query = ['limit' => self::PAGE_SIZE, ...$query, ...($cursor === null || $cursor === '' ? [] : ['cursor' => $cursor])];
        $response = $this->required(self::V2 . $path, $query);
        $next = $response->child('additional_data')?->optionalString('next_cursor');

        return new Page(array_map($assemble, $response->list('data')), $next !== null, $next);
    }

    /**
     * Every item of an API v1 list.
     *
     * @param array<string, scalar> $query
     *
     * @return list<Node>
     */
    private function v1All(string $path, array $query = []): array
    {
        $items = [];
        $start = 0;
        do {
            $response = $this->required(self::V1 . $path, [...$query, 'start' => $start, 'limit' => self::V1_PAGE_SIZE]);
            $items = [...$items, ...(is_array($response->raw()['data'] ?? null) ? $response->list('data') : [])];
            $pagination = self::pagination($response);
            $more = ($pagination?->raw()['more_items_in_collection'] ?? false) === true;
            $start = $pagination?->optionalInt('next_start') ?? $start + self::V1_PAGE_SIZE;
        } while ($more);

        return $items;
    }

    /**
     * An API v1 list's paging: under `additional_data.pagination` (notes), or `additional_data` itself (the field
     * lists); null for a list that isn't paged (filters).
     */
    private static function pagination(Node $response): ?Node
    {
        $additional = $response->child('additional_data');

        return $additional?->child('pagination') ?? $additional;
    }

    private function person(Node $person): Node
    {
        return self::identified('Person', $person->int('id'), [
            ...$person->raw(),
            'filter_ids' => $this->filterMembers()[$person->int('id')] ?? [],
            'field_definitions' => self::raw($this->customDefinitions('personFields')),
        ]);
    }

    private function organization(Node $organization): Node
    {
        $industry = $organization->optionalInt(self::INDUSTRY_FIELD);

        return self::identified('Organization', $organization->int('id'), [
            ...$organization->raw(),
            'industry_label' => $industry === null ? null : $this->optionLabel('organizationFields', self::INDUSTRY_FIELD, (string) $industry),
            'field_definitions' => self::raw($this->customDefinitions('organizationFields')),
        ]);
    }

    private function product(Node $product): Node
    {
        $variations = [];
        $cursor = null;
        do {
            $response = $this->required(self::V2 . '/products/' . $product->int('id') . '/variations', ['limit' => self::PAGE_SIZE, ...($cursor === null ? [] : ['cursor' => $cursor])]);
            foreach (is_array($response->raw()['data'] ?? null) ? $response->list('data') : [] as $variation) {
                $variations[] = $variation->raw();
            }
            $cursor = $response->child('additional_data')?->optionalString('next_cursor');
        } while ($cursor !== null);

        return self::identified('Product', $product->int('id'), [
            ...$product->raw(),
            'variations' => $variations,
            'currency' => $this->currency(),
            'field_definitions' => self::raw($this->customDefinitions('productFields')),
        ]);
    }

    /** Notes, then done activities: the cursor is `<0 for notes, 1 for activities>:<their own cursor>`. */
    private function notes(?string $cursor): Page
    {
        [$part, $inner] = array_pad(explode(self::CURSOR_SEPARATOR, $cursor ?? '', 2), 2, '');
        if ($part === '' || $part === '0') {
            $start = $inner === '' ? 0 : (int) $inner;
            $response = $this->required(self::V1 . '/notes', ['start' => $start, 'limit' => self::V1_PAGE_SIZE]);
            $nodes = [];
            foreach (is_array($response->raw()['data'] ?? null) ? $response->list('data') : [] as $note) {
                $nodes = [...$nodes, ...self::perOwner('Note', $note, 'note', [$note->optionalInt('person_id')], $note->optionalInt('org_id'))];
            }
            $pagination = self::pagination($response);
            $more = ($pagination?->raw()['more_items_in_collection'] ?? false) === true;
            $next = $pagination?->optionalInt('next_start') ?? $start + self::V1_PAGE_SIZE;

            return new Page($nodes, true, $more ? '0' . self::CURSOR_SEPARATOR . $next : '1' . self::CURSOR_SEPARATOR);
        }
        $page = $this->v2('/activities', $inner === '' ? null : $inner, ['done' => true], static fn(Node $activity): Node => $activity);
        $nodes = [];
        foreach ($page->nodes as $activity) {
            $people = array_map(static fn(Node $participant): ?int => $participant->optionalInt('person_id'), is_array($activity->raw()['participants'] ?? null) ? $activity->list('participants') : []);
            $nodes = [...$nodes, ...self::perOwner('Activity', $activity, 'activity', [$activity->optionalInt('person_id'), ...$people], $activity->optionalInt('org_id'))];
        }

        return new Page($nodes, $page->hasNextPage, $page->hasNextPage ? '1' . self::CURSOR_SEPARATOR . $page->endCursor : null);
    }

    /**
     * A note or activity as one node per person and per organization it belongs to.
     *
     * @param list<?int> $people
     *
     * @return list<Node>
     */
    private static function perOwner(string $type, Node $item, string $record, array $people, ?int $organization): array
    {
        $nodes = [];
        foreach (array_values(array_unique(array_filter($people, static fn(?int $id): bool => $id !== null))) as $person) {
            $nodes[] = self::identified($type, $item->int('id') . "-person-{$person}", [...$item->raw(), 'record' => $record, 'belongs_to_type' => 'Person', 'belongs_to_id' => $person]);
        }
        if ($organization !== null) {
            $nodes[] = self::identified($type, $item->int('id') . "-org-{$organization}", [...$item->raw(), 'record' => $record, 'belongs_to_type' => 'Organization', 'belongs_to_id' => $organization]);
        }

        return $nodes;
    }

    /** @return list<Node> the person filters */
    private function filters(): array
    {
        return $this->v1All('/filters', ['type' => 'people']);
    }

    /** @return array<int, list<int>> person id → the ids of the person filters it matches */
    private function filterMembers(): array
    {
        if ($this->filterMembers !== null) {
            return $this->filterMembers;
        }
        $members = [];
        foreach ($this->filters() as $filter) {
            $cursor = null;
            do {
                $page = $this->v2('/persons', $cursor, ['filter_id' => $filter->int('id')], static fn(Node $person): Node => $person);
                foreach ($page->nodes as $person) {
                    $members[$person->int('id')][] = $filter->int('id');
                }
                $cursor = $page->endCursor;
            } while ($page->hasNextPage);
        }

        return $this->filterMembers = $members;
    }

    /** @return list<Node> a field kind's definitions */
    private function definitions(string $fields): array
    {
        return $this->fieldDefinitions[$fields] ??= $this->v1All("/{$fields}");
    }

    /** @return list<Node> the account's own fields of a kind (those it can edit) */
    private function customDefinitions(string $fields): array
    {
        return array_values(array_filter($this->definitions($fields), static fn(Node $definition): bool => ($definition->raw()['edit_flag'] ?? false) === true));
    }

    /**
     * The options of a field (the first of $keys a definition has), as nodes of a type.
     *
     * @param string|list<string> $keys
     *
     * @return list<Node>
     */
    private function options(string $fields, string|array $keys, string $type): array
    {
        foreach ((array) $keys as $key) {
            foreach ($this->definitions($fields) as $definition) {
                if ($definition->string('key') === $key) {
                    return array_map(static fn(Node $option): Node => self::identified($type, $option->int('id'), $option->raw()), is_array($definition->raw()['options'] ?? null) ? $definition->list('options') : []);
                }
            }
        }

        return [];
    }

    private function optionLabel(string $fields, string $key, string $id): ?string
    {
        foreach ($this->options($fields, $key, 'Option') as $option) {
            if (Identity::sourceId($option->id()) === $id) {
                return $option->optionalString('label');
            }
        }

        return null;
    }

    /** The company's default currency (the connected user's). */
    private function currency(): string
    {
        return $this->currency ??= $this->required(self::V1 . '/users/me')->requiredChild('data')->string('default_currency');
    }

    /**
     * @param array<string, scalar> $query
     */
    private function required(string $path, array $query = []): Node
    {
        return $this->client->get($path, $query) ?? throw new SourceError("Pipedrive has no {$path} for this company.");
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<array<array-key, mixed>>
     */
    private static function raw(array $nodes): array
    {
        return array_map(static fn(Node $node): array => $node->raw(), $nodes);
    }

    /** @param array<array-key, mixed> $raw */
    private static function identified(string $type, int|string $id, array $raw): Node
    {
        $gid = Fields::id($type, $id);

        return new Node([...$raw, 'id' => $gid], $gid);
    }
}
