<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\ContentTypes;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Meta\WpMeta;
use IdempotentImporter\Wp\Posts;
use IdempotentImporter\Wp\Retirement;

/**
 * CMS collections → custom post types (see ContentTypes): the collection's name, singular name, description
 * and URL base. The post type key comes from the collection's identity, so renaming it keeps its items. Its
 * field definitions are kept as meta. A retired collection's definition is trashed (no longer registered).
 */
final class ContentTypeWriter implements Writer
{
    private const CONSUMED = ['name', 'singular_name', 'slug', 'description'];

    public function kind(): Kind
    {
        return Kind::ContentType;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $id = Posts::upsert(ContentTypes::DEFINITION_TYPE, $existing?->targetId, [
            'post_title' => $f->string('name'),
            'post_name' => $f->string('slug'),
            'post_content' => $f->nullableString('description') ?? '',
            'post_status' => 'publish',
            ...Posts::keptDates($existing?->targetId),
        ]);
        $meta = WpMeta::post($id);
        MetaSync::fields($meta, [
            ContentTypes::KEY_META => ContentTypes::key($desired->gid),
            ContentTypes::SINGULAR_META => $f->string('singular_name'),
            ContentTypes::BASE_META => $f->string('slug'),
        ]);
        MetaSync::mirror($meta, $desired->gid, $f, self::CONSUMED);

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return Posts::exists($targetId, ContentTypes::DEFINITION_TYPE);
    }

    public function retire(Link $link): void
    {
        Retirement::post($link->targetId);
    }
}
