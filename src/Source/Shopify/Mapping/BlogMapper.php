<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/** Blog → post category. */
final class BlogMapper implements Mapper
{
    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::Blog;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        return new Desired(Kind::Blog, $node->id(), [
            'name' => $node->string('title'),
            'slug' => $node->string('handle'),
            'comment_policy' => $node->string('commentPolicy'),
            'template_suffix' => $node->optionalString('templateSuffix'),
            'meta' => Fields::metafields($node),
        ], [LegacyPaths::blog($node->string('handle'))]);
    }
}
