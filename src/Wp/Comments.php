<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp;

use IdempotentShopifyImporter\Shopify\ShopifyError;

/**
 * Creating or updating a mirrored comment (blog comments and product reviews).
 *
 * @phpstan-type CommentFields array{comment_post_ID: int, comment_type?: string, comment_author: string, comment_author_email: string, comment_content: string, comment_approved: string, comment_author_IP?: string, comment_agent?: string, comment_date: string, comment_date_gmt: string}
 */
final class Comments
{
    /** @param CommentFields $fields */
    public static function upsert(?int $existingId, array $fields, string $what): int
    {
        if ($existingId !== null && get_comment($existingId) !== null) {
            if (is_wp_error(wp_update_comment(wp_slash(['comment_ID' => $existingId, ...$fields]), true))) {
                throw new ShopifyError("Could not update {$what}.");
            }

            return $existingId;
        }
        $id = wp_insert_comment(wp_slash($fields));
        if ($id === false) {
            throw new ShopifyError("Could not save {$what}.");
        }

        return $id;
    }

    /** @return array{comment_date: string, comment_date_gmt: string} */
    public static function dates(string $iso): array
    {
        return ['comment_date' => Dates::local($iso), 'comment_date_gmt' => Dates::gmt($iso)];
    }
}
