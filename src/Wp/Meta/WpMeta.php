<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Meta;

/** Post, term, comment or user meta through WordPress's generic metadata API. */
final readonly class WpMeta implements MetaTarget
{
    private function __construct(private string $type, private int $id) {}

    public static function post(int $id): self
    {
        return new self('post', $id);
    }

    public static function term(int $id): self
    {
        return new self('term', $id);
    }

    public static function user(int $id): self
    {
        return new self('user', $id);
    }

    public static function comment(int $id): self
    {
        return new self('comment', $id);
    }

    public function get(string $key): mixed
    {
        return get_metadata($this->type, $this->id, $key, true);
    }

    public function set(string $key, string|array $value): void
    {
        update_metadata($this->type, $this->id, $key, $value);
    }

    public function delete(string $key): void
    {
        delete_metadata($this->type, $this->id, $key);
    }
}
