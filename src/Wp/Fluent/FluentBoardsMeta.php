<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Fluent;

use FluentBoards\App\Models\Meta;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Meta\MetaTarget;

/**
 * The importer's meta on a FluentBoards board or task (its identity, `_mirror_deleted`, and mapped fields with no
 * native FluentBoards home), kept in FluentBoards' own generic meta table (`fbs_metas`) under an object type of the
 * importer's (`iim_<kind>`), so FluentBoards' own meta is never touched. Saved quietly (no FluentBoards event).
 */
final readonly class FluentBoardsMeta implements MetaTarget
{
    private const OBJECT_TYPE_PREFIX = 'iim_';

    private string $objectType;

    public function __construct(Kind $kind, private int $objectId)
    {
        $this->objectType = self::OBJECT_TYPE_PREFIX . $kind->value;
    }

    public function get(string $key): mixed
    {
        return $this->row($key)?->getAttribute('value');
    }

    public function set(string $key, string|array $value): void
    {
        $row = $this->row($key);
        if ($row === null) {
            $row = new Meta();
            $row->fill(['object_type' => $this->objectType, 'object_id' => $this->objectId, 'key' => $key, 'value' => $value]);
            $row->saveQuietly();

            return;
        }
        if ($row->getAttribute('value') !== $value) {
            $row->fill(['value' => $value]);
            $row->saveQuietly();
        }
    }

    public function delete(string $key): void
    {
        $row = $this->row($key);
        if ($row !== null) {
            Meta::withoutEvents(static fn(): ?bool => $row->delete());
        }
    }

    private function row(string $key): ?Meta
    {
        $row = (new Meta())->newQuery()
            ->where('object_type', $this->objectType)
            ->where('object_id', $this->objectId)
            ->where('key', $key)
            ->first();

        return $row instanceof Meta ? $row : null;
    }
}
