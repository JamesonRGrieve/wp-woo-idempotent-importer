<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use InvalidArgumentException;

/**
 * Typed reads of a Desired's fields (or of a nested record in them), so writers never cast mapped data
 * by hand. A field of the wrong type is a programming error between a mapper and its writer.
 */
final readonly class Values
{
    /** @param array<array-key, mixed> $fields */
    public function __construct(private array $fields) {}

    public static function of(Desired $desired): self
    {
        return new self($desired->fields);
    }

    public function string(string $key): string
    {
        $value = $this->fields[$key] ?? null;
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? $value : throw $this->wrong($key, 'string');
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->fields[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw $this->wrong($key, 'string or null');
        }

        return $value === '' ? null : $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->fields[$key] ?? null;

        return is_bool($value) ? $value : throw $this->wrong($key, 'bool');
    }

    public function int(string $key): int
    {
        return $this->nullableInt($key) ?? throw $this->wrong($key, 'int');
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->fields[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw $this->wrong($key, 'int or null');
        }

        return $value;
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $value = $this->fields[$key] ?? [];
        if (!is_array($value) || array_filter($value, static fn(mixed $item): bool => !is_string($item)) !== []) {
            throw $this->wrong($key, 'list of strings');
        }

        /** @var list<string> */
        return array_values($value);
    }

    /** @return array<string, string> */
    public function stringMap(string $key): array
    {
        $value = $this->fields[$key] ?? [];
        if (!is_array($value)) {
            throw $this->wrong($key, 'map of strings');
        }
        $map = [];
        foreach ($value as $name => $item) {
            if (!is_string($item)) {
                throw $this->wrong($key, 'map of strings');
            }
            $map[(string) $name] = $item;
        }

        return $map;
    }

    /** A nested record, or null. */
    public function record(string $key): ?self
    {
        $value = $this->fields[$key] ?? null;
        if ($value !== null && !is_array($value)) {
            throw $this->wrong($key, 'record or null');
        }

        return $value === null ? null : new self($value);
    }

    /** @return list<self> */
    public function records(string $key): array
    {
        $value = $this->fields[$key] ?? [];
        if (!is_array($value)) {
            throw $this->wrong($key, 'list of records');
        }

        return array_values(array_map(fn(mixed $item): self => is_array($item) ? new self($item) : throw $this->wrong($key, 'list of records'), $value));
    }

    /** @return array<string, array{type: string, value: string}> */
    public function metafields(string $key = 'meta'): array
    {
        $metafields = [];
        foreach ($this->record($key)?->all() ?? [] as $name => $metafield) {
            $record = is_array($metafield) ? new self($metafield) : throw $this->wrong($key, 'metafields');
            $metafields[(string) $name] = ['type' => $record->string('type'), 'value' => $record->string('value')];
        }

        return $metafields;
    }

    /** @return array{title: ?string, description: ?string} */
    public function seo(string $key = 'seo'): array
    {
        $seo = $this->record($key);

        return ['title' => $seo?->nullableString('title'), 'description' => $seo?->nullableString('description')];
    }

    /** A field's value as mapped, for fields stored verbatim (e.g. as meta). */
    public function raw(string $key): mixed
    {
        return $this->fields[$key] ?? null;
    }

    /** @return array<array-key, mixed> every field as mapped */
    public function all(): array
    {
        return $this->fields;
    }

    private function wrong(string $key, string $expected): InvalidArgumentException
    {
        return new InvalidArgumentException("Mapped field \"{$key}\" is not a {$expected}.");
    }
}
