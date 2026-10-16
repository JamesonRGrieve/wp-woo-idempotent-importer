<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Shopify\ShopifyNode;

/** Blog comment → post comment. */
final class CommentMapper implements Mapper
{
    private const VERSION = 1;
    private const STATUS = [
        'PUBLISHED' => 'approve',
        'PENDING' => 'hold',
        'UNAPPROVED' => 'hold',
        'SPAM' => 'spam',
        'REMOVED' => 'trash',
    ];

    public function kind(): Kind
    {
        return Kind::Comment;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(ShopifyNode $node): Desired
    {
        $author = $node->requiredChild('author');

        return new Desired(Kind::Comment, $node->id(), [
            'article_gid' => $node->requiredChild('article')->id(),
            'author_name' => $author->string('name'),
            'author_email' => strtolower($author->string('email')),
            'content' => $node->string('body'),
            'status' => self::STATUS[$node->string('status')] ?? 'hold',
            'created_at' => $node->string('createdAt'),
            'ip' => $node->optionalString('ip'),
            'user_agent' => $node->optionalString('userAgent'),
        ]);
    }
}
