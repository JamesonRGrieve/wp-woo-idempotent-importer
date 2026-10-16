<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Meta\WpMeta;
use IdempotentImporter\Wp\Posts;
use IdempotentImporter\Wp\Retirement;
use IdempotentImporter\Wp\Seo;

final class PageWriter implements Writer
{
    private const POST_TYPE = 'page';
    private const CONSUMED = ['title', 'slug', 'content_html', 'status', 'published_at', 'seo'];

    public function __construct(private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return Kind::Page;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $id = Posts::upsert(self::POST_TYPE, $existing?->targetId, [
            'post_title' => $f->string('title'),
            'post_name' => $f->string('slug'),
            'post_content' => $this->targets->html->prepare($f->string('content_html')),
            'post_status' => $f->string('status'),
            ...Posts::dates($f->string('published_at')),
        ]);
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
}
