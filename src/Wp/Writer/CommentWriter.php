<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp\Writer;

use IdempotentShopifyImporter\Mapping\Desired;
use IdempotentShopifyImporter\Mapping\Values;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Store\Link;
use IdempotentShopifyImporter\Wp\Comments;
use IdempotentShopifyImporter\Wp\Meta\MetaSync;
use IdempotentShopifyImporter\Wp\Meta\WpMeta;
use IdempotentShopifyImporter\Wp\Retirement;

/** Blog comments → comments on the mirrored post. */
final class CommentWriter implements Writer
{
    private const APPROVED = ['approve' => '1', 'hold' => '0', 'spam' => 'spam', 'trash' => 'trash'];

    public function __construct(private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return Kind::Comment;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $id = Comments::upsert($existing?->targetId, [
            'comment_post_ID' => $this->targets->require(Kind::Article, $f->string('article_gid')),
            'comment_author' => $f->string('author_name'),
            'comment_author_email' => $f->string('author_email'),
            'comment_content' => $f->string('content'),
            'comment_approved' => self::APPROVED[$f->string('status')] ?? '0',
            'comment_author_IP' => $f->nullableString('ip') ?? '',
            'comment_agent' => $f->nullableString('user_agent') ?? '',
            ...Comments::dates($f->string('created_at')),
        ], "comment {$desired->gid}");
        MetaSync::fields(WpMeta::comment($id), [MetaSync::GID => $desired->gid, MetaSync::DELETED => null]);

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return get_comment($targetId) !== null;
    }

    public function retire(Link $link): void
    {
        Retirement::comment($link->targetId);
    }
}
