<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Source\SourceError;
use WC_Product_Attribute;

/**
 * WooCommerce global attributes (pa_* taxonomies) for Shopify options, so the same option across
 * products is one filterable attribute.
 */
final class GlobalAttributes
{
    /** WooCommerce rejects attribute slugs longer than this. */
    private const MAX_SLUG_LENGTH = 28;
    private const RESERVED_SUFFIX = '-option';

    /**
     * A product attribute with these values, in this order.
     *
     * @param list<string> $values
     */
    public static function attribute(string $name, array $values, int $position, bool $variation): WC_Product_Attribute
    {
        $taxonomy = self::ensureTaxonomy($name);
        $attribute = new WC_Product_Attribute();
        $attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
        $attribute->set_name($taxonomy);
        $attribute->set_options(Terms::idsByName($taxonomy, $values));
        $attribute->set_position($position);
        $attribute->set_visible(true);
        $attribute->set_variation($variation);

        return $attribute;
    }

    /** The term slug a variation stores for an option value. */
    public static function valueSlug(string $name, string $value): string
    {
        $term = get_term_by('name', $value, self::taxonomyName($name));
        if (!$term instanceof \WP_Term) {
            throw new SourceError("Option value \"{$value}\" of \"{$name}\" has no attribute term.");
        }

        return $term->slug;
    }

    public static function taxonomyName(string $name): string
    {
        return wc_attribute_taxonomy_name(self::slug($name));
    }

    private static function ensureTaxonomy(string $name): string
    {
        $slug = self::slug($name);
        $taxonomy = wc_attribute_taxonomy_name($slug);
        if (wc_attribute_taxonomy_id_by_name($taxonomy) === 0) {
            $id = wc_create_attribute(['name' => wp_strip_all_tags($name), 'slug' => $slug, 'type' => 'select', 'has_archives' => false]);
            if (is_wp_error($id)) {
                throw new SourceError("Could not create the attribute \"{$name}\": " . $id->get_error_message());
            }
        }
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, ['product'], ['hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false]);
        }

        return $taxonomy;
    }

    private static function slug(string $name): string
    {
        $slug = wc_sanitize_taxonomy_name($name);
        if (wc_check_if_attribute_name_is_reserved($slug)) {
            $slug .= self::RESERVED_SUFFIX;
        }

        return substr($slug, 0, self::MAX_SLUG_LENGTH);
    }
}
