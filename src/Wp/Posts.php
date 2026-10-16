<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Source\SourceError;
use WP_Post;

/**
 * Creating or updating a mirrored post of a given type.
 *
 * @phpstan-type PostFields array{post_title?: string, post_name?: string, post_content?: string, post_excerpt?: string, post_status?: string, post_author?: int, post_date?: string, post_date_gmt?: string, tags_input?: list<int>, post_category?: list<int>}
 */
final class Posts
{
    /** @param PostFields $fields */
    public static function upsert(string $postType, ?int $existingId, array $fields): int
    {
        $existing = $existingId === null ? null : get_post($existingId);
        $args = ['post_type' => $postType, ...$fields];
        if ($existing instanceof WP_Post && $existing->post_type === $postType) {
            $args['ID'] = $existing->ID;
        }
        $id = wp_insert_post(wp_slash($args), true);
        if (is_wp_error($id)) {
            throw new SourceError("Could not save {$postType} \"" . ($fields['post_title'] ?? '') . '": ' . $id->get_error_message());
        }

        return $id;
    }

    /** @return array{post_date: string, post_date_gmt: string} */
    public static function dates(string $iso): array
    {
        return ['post_date' => Dates::local($iso), 'post_date_gmt' => Dates::gmt($iso)];
    }

    /**
     * The dates a post was given when first mirrored, for a source with no publication time: an update keeps
     * them (WordPress would otherwise re-date the post to now); a new post has none and is dated now.
     *
     * @return array{post_date?: string, post_date_gmt?: string}
     */
    public static function keptDates(?int $postId): array
    {
        $post = $postId === null ? null : get_post($postId);

        return $post instanceof WP_Post ? ['post_date' => $post->post_date, 'post_date_gmt' => $post->post_date_gmt] : [];
    }

    public static function exists(int $postId, string $postType): bool
    {
        $post = get_post($postId);

        return $post instanceof WP_Post && $post->post_type === $postType;
    }
}
