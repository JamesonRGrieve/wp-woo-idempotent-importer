<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A post of the blog collection → a WordPress post in the blog's category: its name, slug, rich text body and
 * featured image (see ItemContent), dated when it was created; drafts are drafts and archived posts private.
 * Every other field the collection defines (summaries, authors, categories as references) is meta. Its old URL
 * (`/<collection>/<slug>`) redirects to it.
 */
final class ArticleMapper implements Mapper
{
    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::Article;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $content = ItemContent::of($node);

        return new Desired(Kind::Article, $node->id(), [
            'title' => $content->title,
            'slug' => $content->slug,
            'content_html' => $content->bodyHtml ?? '',
            'excerpt_html' => null,
            'tags' => [],
            'status' => Fields::status($node),
            'published_at' => Fields::time($node, 'createdOn'),
            'blog_gid' => Fields::collectionId($node->string('collection_id')),
            'image' => $content->image,
            'author' => null,
            'seo' => ['title' => null, 'description' => null],
            'fields' => $content->fields,
        ], ['/' . $node->string('collection_slug') . '/' . $content->slug]);
    }
}
