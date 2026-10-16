<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\Comments;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Meta\WpMeta;
use IdempotentImporter\Wp\Retirement;
use WC_Comments;
use WP_Post;

/**
 * Reviews → WooCommerce product reviews, on the product the review names: by its mirrored identity
 * (`product_gid`), or by URL handle for review exports that only carry one (`product_handle`). Review
 * text is written by customers, so it is always filtered as comment HTML, whatever the raw-HTML setting.
 */
final class ReviewWriter implements Writer
{
    private const COMMENT_TYPE = 'review';
    private const PRODUCT_POST_TYPE = 'product';
    private const CONSUMED = [
        'product_gid', 'product_handle', 'rating', 'content', 'author', 'email', 'created_at', 'approved', 'verified', 'reply',
    ];

    public function __construct(private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return Kind::Review;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $productId = $this->productId($f);
        $id = Comments::upsert($existing?->targetId, [
            'comment_post_ID' => $productId,
            'comment_type' => self::COMMENT_TYPE,
            'comment_author' => $f->string('author'),
            'comment_author_email' => $f->string('email'),
            'comment_content' => wp_filter_kses($f->string('content')),
            'comment_approved' => $f->bool('approved') ? '1' : '0',
            ...Comments::dates($f->string('created_at')),
        ], "review {$desired->gid}");
        $meta = WpMeta::comment($id);
        MetaSync::fields($meta, [
            'rating' => $f->int('rating'),
            'verified' => $f->bool('verified') ? 1 : 0,
            '_mirror_reply' => $f->nullableString('reply'),
        ]);
        MetaSync::mirror($meta, $desired->gid, $f, self::CONSUMED);
        WC_Comments::clear_transients($productId);

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

    /** The reviewed product; one that isn't mirrored yet is an UnresolvedDependency, retried next run. */
    private function productId(Values $f): int
    {
        $gid = $f->nullableString('product_gid');
        if ($gid !== null) {
            return $this->targets->require(Kind::Product, $gid);
        }
        $handle = $f->string('product_handle');
        $product = get_page_by_path($handle, OBJECT, self::PRODUCT_POST_TYPE);

        return $product instanceof WP_Post ? $product->ID : throw new UnresolvedDependency("No product with the handle \"{$handle}\".");
    }
}
