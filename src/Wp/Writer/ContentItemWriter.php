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
use IdempotentImporter\Wp\Seo;
use WP_Post;

/**
 * CMS collection items → posts of their collection's post type (`type_gid`): title, slug, rich text, excerpt,
 * status, publication date and image. Every other field the collection defines is kept in `_mirror_fields`
 * meta, by field slug (a reference names the referenced item's identity). An item with no publication date
 * is dated when it is first mirrored.
 */
final class ContentItemWriter implements Writer
{
    private const CONSUMED = ['type_gid', 'title', 'slug', 'content_html', 'excerpt_html', 'status', 'published_at', 'image', 'seo'];

    public function __construct(private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return Kind::ContentItem;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $typeGid = $f->string('type_gid');
        $this->targets->require(Kind::ContentType, $typeGid);
        $publishedAt = $f->nullableString('published_at');
        $id = Posts::upsert(ContentTypes::key($typeGid), $existing?->targetId, [
            'post_title' => $f->string('title'),
            'post_name' => $f->string('slug'),
            'post_content' => $this->targets->html->prepare($f->nullableString('content_html') ?? ''),
            'post_excerpt' => $this->targets->html->prepare($f->nullableString('excerpt_html') ?? ''),
            'post_status' => $f->string('status'),
            'post_author' => $this->targets->settings->defaultAuthor,
            ...($publishedAt === null ? Posts::keptDates($existing?->targetId) : Posts::dates($publishedAt)),
        ]);
        $image = $f->record('image');
        if ($image === null) {
            delete_post_thumbnail($id);
        } else {
            set_post_thumbnail($id, $this->targets->media->attachment($image->string('url'), $image->nullableString('alt'), $id));
        }
        $meta = WpMeta::post($id);
        MetaSync::mirror($meta, $desired->gid, $f, self::CONSUMED);
        Seo::write($meta, $f->seo());

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return get_post($targetId) instanceof WP_Post;
    }

    public function retire(Link $link): void
    {
        Retirement::post($link->targetId);
    }
}
