<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp\Writer;

use IdempotentShopifyImporter\Mapping\Desired;
use IdempotentShopifyImporter\Mapping\Values;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Store\Link;
use IdempotentShopifyImporter\Wp\Meta\MetaSync;
use IdempotentShopifyImporter\Wp\Meta\WpMeta;
use IdempotentShopifyImporter\Wp\Posts;
use IdempotentShopifyImporter\Wp\Retirement;
use IdempotentShopifyImporter\Wp\Seo;
use IdempotentShopifyImporter\Wp\Terms;
use WP_User;

/** Articles → posts in their blog's category, with tags, featured image and author. */
final class ArticleWriter implements Writer
{
    private const POST_TYPE = 'post';
    private const TAG_TAXONOMY = 'post_tag';
    private const CONSUMED = ['title', 'slug', 'content_html', 'excerpt_html', 'tags', 'status', 'published_at', 'blog_gid', 'image', 'seo'];

    public function __construct(private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return Kind::Article;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $category = $this->targets->settings->syncs(Kind::Blog) ? [$this->targets->require(Kind::Blog, $f->string('blog_gid'))] : null;
        $args = [
            'post_title' => $f->string('title'),
            'post_name' => $f->string('slug'),
            'post_content' => $this->targets->html->prepare($f->string('content_html')),
            'post_excerpt' => $this->targets->html->prepare($f->nullableString('excerpt_html') ?? ''),
            'post_status' => $f->string('status'),
            'post_author' => $this->author($f->nullableString('author')),
            'tags_input' => Terms::idsByName(self::TAG_TAXONOMY, $f->strings('tags')),
            ...Posts::dates($f->string('published_at')),
        ];
        if ($category !== null) {
            $args['post_category'] = $category;
        }
        $id = Posts::upsert(self::POST_TYPE, $existing?->targetId, $args);
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
        return Posts::exists($targetId, self::POST_TYPE);
    }

    public function retire(Link $link): void
    {
        Retirement::post($link->targetId);
    }

    /** The WordPress user whose display name is the Shopify author, else the configured default. */
    private function author(?string $name): int
    {
        if ($name !== null) {
            $users = get_users(['search' => $name, 'search_columns' => ['display_name'], 'number' => 1]);
            if (($users[0] ?? null) instanceof WP_User) {
                return $users[0]->ID;
            }
        }

        return $this->targets->settings->defaultAuthor;
    }
}
