<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

use IdempotentImporter\Support\Decimal;
use InvalidArgumentException;

/**
 * Typed, validated read access to one object of a source platform's response. Every read names the field
 * it expected, so a response that doesn't match the schema fails loudly at the boundary with its path.
 */
final readonly class Node
{
    /** @param array<array-key, mixed> $data */
    public function __construct(private array $data, private string $path = '$') {}

    /** @return array<array-key, mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    /**
     * The named fields that are present, as sent (an object's details kept as meta).
     *
     * @param list<string> $fields
     *
     * @return array<array-key, mixed>
     */
    public function only(array $fields): array
    {
        return array_intersect_key($this->data, array_flip($fields));
    }

    public function has(string $field): bool
    {
        return isset($this->data[$field]);
    }

    public function string(string $field): string
    {
        $value = $this->data[$field] ?? null;
        if (!is_string($value)) {
            throw $this->mismatch($field, 'a string');
        }

        return $value;
    }

    public function optionalString(string $field): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw $this->mismatch($field, 'a string or null');
        }

        return $value === '' ? null : $value;
    }

    public function bool(string $field): bool
    {
        $value = $this->data[$field] ?? null;
        if (!is_bool($value)) {
            throw $this->mismatch($field, 'a boolean');
        }

        return $value;
    }

    public function optionalInt(string $field): ?int
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        throw $this->mismatch($field, 'an integer or null');
    }

    public function int(string $field): int
    {
        return $this->optionalInt($field) ?? throw $this->mismatch($field, 'an integer');
    }

    /**
     * A decimal sent as a string or a JSON number, as a plain decimal string (see Decimal::of: a number is
     * read exactly as the JSON spelled it, exponent included, never rounded).
     */
    public function optionalDecimal(string $field): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw $this->mismatch($field, 'a decimal or null');
        }
        try {
            return Decimal::of($value);
        } catch (InvalidArgumentException) {
            throw $this->mismatch($field, 'a decimal or null');
        }
    }

    public function decimal(string $field): string
    {
        return $this->optionalDecimal($field) ?? throw $this->mismatch($field, 'a decimal');
    }

    /** @return list<string> */
    public function strings(string $field): array
    {
        $value = $this->data[$field] ?? [];
        if (!is_array($value)) {
            throw $this->mismatch($field, 'a list of strings');
        }
        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw $this->mismatch($field, 'a list of strings');
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /** @return list<int> */
    public function ints(string $field): array
    {
        $value = $this->data[$field] ?? [];
        if (!is_array($value)) {
            throw $this->mismatch($field, 'a list of integers');
        }
        $ints = [];
        foreach ($value as $item) {
            if (!is_int($item)) {
                throw $this->mismatch($field, 'a list of integers');
            }
            $ints[] = $item;
        }

        return $ints;
    }

    public function child(string $field): ?self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw $this->mismatch($field, 'an object or null');
        }

        return new self($value, "{$this->path}.{$field}");
    }

    public function requiredChild(string $field): self
    {
        return $this->child($field) ?? throw $this->mismatch($field, 'an object');
    }

    /**
     * The items of a list field, or of a connection's `nodes`.
     *
     * @return list<self>
     */
    public function list(string $field): array
    {
        $value = $this->data[$field] ?? [];
        if (is_array($value) && array_key_exists('nodes', $value)) {
            $value = $value['nodes'];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->mismatch($field, 'a list or connection');
        }
        $items = [];
        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                throw $this->mismatch("{$field}[{$index}]", 'an object');
            }
            $items[] = new self($item, "{$this->path}.{$field}[{$index}]");
        }

        return $items;
    }

    /** The GraphQL object type of a union/interface member (requires `__typename` in the selection). */
    public function typename(): string
    {
        return $this->string('__typename');
    }

    public function id(): string
    {
        return $this->string('id');
    }

    private function mismatch(string $field, string $expected): SourceError
    {
        return new SourceError("Source response {$this->path}.{$field} is not {$expected}");
    }
}
