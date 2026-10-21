<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

/**
 * The post types of mirrored CMS collections. Each collection is kept as a private `iim_content_type` post
 * (ContentTypeWriter) naming its post type key, labels and URL base, and registered as a public post type on
 * every request, so its items are ordinary WordPress posts with their own archive and URLs. A trashed
 * (retired) collection is no longer registered; its items stay in the database.
 */
final class ContentTypes
{
    public const DEFINITION_TYPE = 'iim_content_type';
    public const KEY_META = '_iim_post_type';
    public const SINGULAR_META = '_iim_singular_name';
    public const BASE_META = '_iim_url_base';
    /** WordPress's limit on a post type key. */
    public const MAX_KEY_LENGTH = 20;
    public const KEY_PREFIX = 'iim_';
    private const SUPPORTS = ['title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions'];
    private const MENU_ICON = 'dashicons-database';

    public function register(): void
    {
        add_action('init', $this->registerTypes(...));
    }

    /** The post type key of a collection, from its identity (stable, and within WordPress's 20 characters). */
    public static function key(string $gid): string
    {
        return self::KEY_PREFIX . substr(hash('sha256', $gid), 0, self::MAX_KEY_LENGTH - strlen(self::KEY_PREFIX));
    }

    public function registerTypes(): void
    {
        register_post_type(self::DEFINITION_TYPE, ['public' => false, 'show_ui' => false, 'rewrite' => false, 'query_var' => false]);
        $definitions = get_posts(['post_type' => self::DEFINITION_TYPE, 'post_status' => 'publish', 'numberposts' => -1]);
        foreach ($definitions as $definition) {
            $key = get_post_meta($definition->ID, self::KEY_META, true);
            $key = is_string($key) ? strtolower($key) : '';
            if ($key === '' || post_type_exists($key)) {
                continue;
            }
            $singular = get_post_meta($definition->ID, self::SINGULAR_META, true);
            $base = get_post_meta($definition->ID, self::BASE_META, true);
            register_post_type($key, [
                'label' => $definition->post_title,
                'labels' => ['name' => $definition->post_title, 'singular_name' => is_string($singular) && $singular !== '' ? $singular : $definition->post_title],
                'description' => $definition->post_content,
                'public' => true,
                'show_in_rest' => true,
                'has_archive' => true,
                'menu_icon' => self::MENU_ICON,
                'supports' => self::SUPPORTS,
                'rewrite' => ['slug' => is_string($base) && $base !== '' ? $base : $definition->post_name, 'with_front' => false],
            ]);
        }
    }
}
