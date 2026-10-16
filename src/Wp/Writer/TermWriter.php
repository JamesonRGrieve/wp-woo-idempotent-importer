<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\DynamicCollections;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Meta\WpMeta;
use IdempotentImporter\Wp\Retirement;
use IdempotentImporter\Wp\Seo;
use IdempotentImporter\Wp\Terms;
use WP_Term;

/**
 * A kind mirrored as taxonomy terms: collections → product categories, blogs → post categories. Terms
 * have no trash, so a retired term stays (its products keep it) and is marked.
 */
final class TermWriter implements Writer
{
    public const THUMBNAIL_META = 'thumbnail_id';
    private const CONSUMED = ['name', 'slug', 'description_html', 'image', 'seo'];

    public function __construct(
        private readonly Kind $kind,
        private readonly string $taxonomy,
        private readonly Targets $targets,
    ) {}

    public function kind(): Kind
    {
        return $this->kind;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $id = Terms::upsert(
            $this->taxonomy,
            $existing?->targetId,
            $f->string('name'),
            $f->string('slug'),
            $this->targets->html->prepare($f->nullableString('description_html') ?? ''),
        );
        $meta = WpMeta::term($id);
        $image = $f->record('image');
        MetaSync::fields($meta, [
            self::THUMBNAIL_META => $image === null ? null : $this->targets->media->attachment($image->string('url'), $image->nullableString('alt')),
        ]);
        MetaSync::mirror($meta, $desired->gid, $f, self::CONSUMED);
        DynamicCollections::writeRules($meta, $f->record('smart_rules'));
        Seo::writeTerm($meta, $id, $this->taxonomy, $f->seo());

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return get_term($targetId, $this->taxonomy) instanceof WP_Term;
    }

    public function retire(Link $link): void
    {
        Retirement::mark(WpMeta::term($link->targetId));
    }
}
