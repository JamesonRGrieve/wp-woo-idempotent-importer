<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Fluent;

use FluentCrm\App\Models\Meta;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Wp\Meta\MetaTarget;

/**
 * The importer's meta on a FluentCRM object (its identity, `_mirror_deleted`, and mapped fields with no native
 * FluentCRM home), kept in FluentCRM's own generic meta table (`fc_meta`) under an object type of the importer's
 * (`iim_<kind>`), so FluentCRM's own meta is never touched.
 */
final readonly class FluentMeta implements MetaTarget
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
            $row->save();

            return;
        }
        if ($row->getAttribute('value') !== $value) {
            $row->fill(['value' => $value]);
            $row->save();
        }
    }

    public function delete(string $key): void
    {
        $this->row($key)?->delete();
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
