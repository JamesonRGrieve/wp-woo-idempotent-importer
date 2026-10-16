<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp;

use IdempotentShopifyImporter\Shopify\ShopifyError;
use WP_Term;

/** Taxonomy terms: upsert of a mirrored term, and find-or-create of terms by name (tags, brands). */
final class Terms
{
    /** Creates or updates a mirrored term; an unlinked term with the same slug is adopted. */
    public static function upsert(string $taxonomy, ?int $existingId, string $name, string $slug, string $description, int $parent = 0): int
    {
        $args = ['name' => wp_strip_all_tags($name), 'slug' => $slug, 'description' => $description, 'parent' => $parent];
        $existing = $existingId !== null ? get_term($existingId, $taxonomy) : null;
        if (!$existing instanceof WP_Term) {
            $existing = get_term_by('slug', $slug, $taxonomy);
        }
        if ($existing instanceof WP_Term) {
            if (self::differs($existing, $args)) {
                self::check(wp_update_term($existing->term_id, $taxonomy, $args), $taxonomy, $name);
            }

            return $existing->term_id;
        }

        return self::check(wp_insert_term($args['name'], $taxonomy, $args), $taxonomy, $name);
    }

    /**
     * The ids of the terms with these names, creating any that don't exist.
     *
     * @param list<string> $names
     *
     * @return list<int>
     */
    public static function idsByName(string $taxonomy, array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim(wp_strip_all_tags($name));
            if ($name === '') {
                continue;
            }
            $term = get_term_by('name', $name, $taxonomy);
            $ids[] = $term instanceof WP_Term ? $term->term_id : self::check(wp_insert_term($name, $taxonomy), $taxonomy, $name);
        }

        return array_values(array_unique($ids));
    }

    /** @param array{name: string, slug: string, description: string, parent: int} $args */
    private static function differs(WP_Term $term, array $args): bool
    {
        return $term->name !== $args['name'] || $term->slug !== $args['slug']
            || $term->description !== $args['description'] || $term->parent !== $args['parent'];
    }

    /** @param array<array-key, mixed>|\WP_Error $result what wp_insert_term / wp_update_term returned */
    private static function check(array|\WP_Error $result, string $taxonomy, string $name): int
    {
        if (is_wp_error($result)) {
            throw new ShopifyError("Could not save {$taxonomy} term \"{$name}\": " . $result->get_error_message());
        }
        $id = $result['term_id'] ?? null;
        if (!is_numeric($id)) {
            throw new ShopifyError("Saving {$taxonomy} term \"{$name}\" returned no term id.");
        }

        return (int) $id;
    }
}
