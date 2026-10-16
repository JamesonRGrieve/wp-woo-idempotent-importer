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
use WC_Comments;
use WP_Post;

/**
 * Review rows → WooCommerce product reviews on the product with the row's handle. Review text is
 * written by customers, so it is always filtered as comment HTML, whatever the raw-HTML setting.
 */
final class ReviewWriter implements Writer
{
    private const COMMENT_TYPE = 'review';
    private const PRODUCT_POST_TYPE = 'product';

    public function kind(): Kind
    {
        return Kind::Review;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $product = get_page_by_path($f->string('product_handle'), OBJECT, self::PRODUCT_POST_TYPE);
        if (!$product instanceof WP_Post) {
            throw new UnresolvedDependency("No product with the handle \"{$f->string('product_handle')}\".");
        }
        $id = Comments::upsert($existing?->targetId, [
            'comment_post_ID' => $product->ID,
            'comment_type' => self::COMMENT_TYPE,
            'comment_author' => $f->string('author'),
            'comment_author_email' => $f->string('email'),
            'comment_content' => wp_filter_kses($f->string('content')),
            'comment_approved' => $f->bool('approved') ? '1' : '0',
            ...Comments::dates($f->string('created_at')),
        ], "review {$desired->gid}");
        MetaSync::fields(WpMeta::comment($id), [
            MetaSync::GID => $desired->gid,
            'rating' => $f->int('rating'),
            'verified' => $f->bool('verified') ? 1 : 0,
            '_shopify_reply' => $f->nullableString('reply'),
        ]);
        WC_Comments::clear_transients($product->ID);

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
