<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Meta\MetaTarget;
use WPSEO_Taxonomy_Meta;

/**
 * SEO title/description. WordPress core has no SEO fields, so they are written where Yoast SEO and Rank
 * Math read them (harmless if neither is active, and picked up if one is activated later), plus the
 * importer's own keys.
 */
final class Seo
{
    private const TITLE_KEYS = ['_mirror_seo_title', '_yoast_wpseo_title', 'rank_math_title'];
    private const DESCRIPTION_KEYS = ['_mirror_seo_description', '_yoast_wpseo_metadesc', 'rank_math_description'];

    /** @param array{title: ?string, description: ?string} $seo */
    public static function write(MetaTarget $target, array $seo): void
    {
        $values = [];
        foreach (self::TITLE_KEYS as $key) {
            $values[$key] = $seo['title'];
        }
        foreach (self::DESCRIPTION_KEYS as $key) {
            $values[$key] = $seo['description'];
        }
        MetaSync::fields($target, $values);
    }

    /**
     * Terms additionally go to Yoast's own taxonomy store when Yoast is active.
     *
     * @param array{title: ?string, description: ?string} $seo
     */
    public static function writeTerm(MetaTarget $target, int $termId, string $taxonomy, array $seo): void
    {
        self::write($target, $seo);
        if (class_exists(WPSEO_Taxonomy_Meta::class)) {
            WPSEO_Taxonomy_Meta::set_values($termId, $taxonomy, [
                'wpseo_title' => $seo['title'] ?? '',
                'wpseo_desc' => $seo['description'] ?? '',
            ]);
        }
    }
}
