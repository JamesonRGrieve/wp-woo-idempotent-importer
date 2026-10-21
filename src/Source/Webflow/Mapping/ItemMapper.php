<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * An item of any other CMS collection → a post of its collection's post type: its name, slug, rich text body
 * and featured image (see ItemContent), dated when it was created; drafts are drafts and archived items
 * private. Every other field is meta, by field slug (references as the referenced items' identities). Its old
 * URL (`/<collection>/<slug>`) redirects to it.
 */
final class ItemMapper implements Mapper
{
    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::ContentItem;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $content = ItemContent::of($node);

        return new Desired(Kind::ContentItem, $node->id(), [
            'type_gid' => Fields::collectionId($node->string('collection_id')),
            'title' => $content->title,
            'slug' => $content->slug,
            'content_html' => $content->bodyHtml,
            'excerpt_html' => null,
            'status' => Fields::status($node),
            'published_at' => Fields::time($node, 'createdOn'),
            'image' => $content->image,
            'seo' => ['title' => null, 'description' => null],
            'fields' => $content->fields,
        ], ['/' . $node->string('collection_slug') . '/' . $content->slug]);
    }
}
