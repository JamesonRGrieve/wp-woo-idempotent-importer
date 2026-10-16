<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

/** Untrusted request input (already unslashed), read with a type and sanitised at the boundary. */
final readonly class Input
{
    /** @param array<array-key, mixed> $values */
    public function __construct(private array $values) {}

    public static function post(): self
    {
        return new self(self::unslashed($_POST));
    }

    public static function query(): self
    {
        return new self(self::unslashed($_GET));
    }

    public static function server(): self
    {
        return new self($_SERVER);
    }

    public function text(string $key): string
    {
        $value = $this->values[$key] ?? '';

        return is_scalar($value) ? sanitize_text_field((string) $value) : '';
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? absint($value) : $default;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /** @return list<string> */
    public function keys(string $key): array
    {
        $value = $this->values[$key] ?? [];

        return is_array($value) ? array_values(array_map(static fn(mixed $item): string => is_scalar($item) ? sanitize_key((string) $item) : '', $value)) : [];
    }

    /** @return array<string, string> */
    public function textMap(string $key): array
    {
        $value = $this->values[$key] ?? [];
        $map = [];
        foreach (is_array($value) ? $value : [] as $name => $item) {
            if (is_scalar($item)) {
                $map[sanitize_key((string) $name)] = sanitize_text_field((string) $item);
            }
        }

        return $map;
    }

    /** The raw string (for values such as a request path that are normalised by their consumer). */
    public function raw(string $key): string
    {
        $value = $this->values[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function unslashed(array $values): array
    {
        return wp_unslash($values);
    }
}
