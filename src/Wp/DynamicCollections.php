<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Meta\MetaTarget;
use IdempotentImporter\Wp\Meta\WcDataMeta;
use WC_Product;

/**
 * Hands Shopify smart-collection rules to wp-woo-dynamic-collections and answers its request for facts
 * WooCommerce has no field for (the standard taxonomy category and metafields). The contract is the
 * other plugin's documented term meta and filter, in plain data: neither plugin needs the other loaded.
 */
final class DynamicCollections
{
    public const RULES_META = '_wdc_rules';
    public const EXTRA_FACTS_FILTER = 'wdc_product_extra_facts';
    public const CATEGORY_ID_META = '_mirror_category_id';

    public function register(): void
    {
        add_filter(self::EXTRA_FACTS_FILTER, $this->facts(...), 10, 2);
    }

    /** A collection's rules, or none for a hand-picked collection (the category then stays manual). */
    public static function writeRules(MetaTarget $term, ?Values $rules): void
    {
        MetaSync::fields($term, [self::RULES_META => $rules === null ? null : (string) wp_json_encode($rules->all())]);
    }

    /**
     * @param array<array-key, mixed> $facts what other plugins already supplied (kept as given)
     *
     * @return array<array-key, mixed>
     */
    public function facts(array $facts, WC_Product $product): array
    {
        $meta = new WcDataMeta($product);
        $categoryId = $meta->get(self::CATEGORY_ID_META);
        $variants = [];
        foreach ($product->get_children() as $childId) {
            $variation = wc_get_product($childId);
            if ($variation instanceof WC_Product) {
                $variants[$childId] = MetaSync::readMetafields(new WcDataMeta($variation));
            }
        }

        return $facts + [
            'category_id' => is_string($categoryId) ? $categoryId : null,
            'metafields' => MetaSync::readMetafields($meta),
            'variant_metafields' => $variants,
        ];
    }
}
