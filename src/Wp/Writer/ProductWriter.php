<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp\Writer;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use IdempotentShopifyImporter\Mapping\Desired;
use IdempotentShopifyImporter\Mapping\Values;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Store\Link;
use IdempotentShopifyImporter\Wp\GlobalAttributes;
use IdempotentShopifyImporter\Wp\Meta\MetaSync;
use IdempotentShopifyImporter\Wp\Meta\WcDataMeta;
use IdempotentShopifyImporter\Wp\Options;
use IdempotentShopifyImporter\Wp\Retirement;
use IdempotentShopifyImporter\Wp\Seo;
use IdempotentShopifyImporter\Wp\Terms;
use WC_Data_Exception;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/** Products and their variations, attributes, images, categories, tags and brand. */
final class ProductWriter implements Writer
{
    public const PRODUCT_TYPE_ATTRIBUTE = 'Product type';
    private const BRAND_TAXONOMY = 'product_brand';
    private const TAG_TAXONOMY = 'product_tag';
    private const WEIGHT_UNITS = ['KILOGRAMS' => 'kg', 'GRAMS' => 'g', 'POUNDS' => 'lbs', 'OUNCES' => 'oz'];
    private const DEFAULT_WEIGHT_UNIT = 'kg';
    /** WooCommerce's Cost of Goods field; the cost is always also kept as `_shopify_unit_cost` meta. */
    private const COST_OF_GOODS_FEATURE = 'cost_of_goods_sold';
    /** Mapped fields stored in native WooCommerce fields; the rest are mirrored as meta. */
    private const CONSUMED = [
        'title', 'slug', 'description_html', 'status', 'catalog_visibility', 'published_at', 'created_at',
        'tags', 'collections', 'images', 'attributes', 'variants', 'simple', 'seo',
    ];

    public function __construct(private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return Kind::Product;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $simple = $f->bool('simple');
        $variants = $f->records('variants');
        $categoryIds = $this->targets->settings->syncs(Kind::Collection)
            ? $this->targets->requireAll(Kind::Collection, $f->strings('collections')) : null;
        $targetId = $existing === null ? 0 : $existing->targetId;
        $product = $simple ? new WC_Product_Simple($targetId) : new WC_Product_Variable($targetId);
        $product->set_name($f->string('title'));
        $product->set_slug($f->string('slug'));
        $product->set_description($this->targets->html->prepare($f->string('description_html')));
        $product->set_status($f->string('status'));
        $product->set_catalog_visibility($f->string('catalog_visibility'));
        $product->set_date_created($f->nullableString('published_at') ?? $f->string('created_at'));
        if ($categoryIds !== null) {
            $product->set_category_ids($categoryIds);
        }
        $product->set_tag_ids(Terms::idsByName(self::TAG_TAXONOMY, $f->strings('tags')));
        $this->images($product, $f->records('images'));
        $product->set_attributes($this->attributes($f));
        if ($simple && isset($variants[0])) {
            $this->variantFields($product, $variants[0], $desired->gid);
        }
        $meta = new WcDataMeta($product);
        MetaSync::mirror($meta, $desired->gid, $f, self::CONSUMED);
        Seo::write($meta, $f->seo());
        $id = $product->save();
        $this->brand($id, $f->nullableString('brand'));
        if ($simple && isset($variants[0])) {
            $this->targets->links->save(Kind::Variant, $variants[0]->string('gid'), $id);
        } else {
            $this->variations($id, $variants);
        }

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return wc_get_product($targetId) instanceof WC_Product;
    }

    public function retire(Link $link): void
    {
        $product = wc_get_product($link->targetId);
        Retirement::wcObject($product instanceof WC_Product ? $product : null);
    }

    /** @param list<Values> $images featured image first, then the gallery */
    private function images(WC_Product $product, array $images): void
    {
        $ids = array_map(
            fn(Values $image): int => $this->targets->media->attachment($image->string('url'), $image->nullableString('alt'), $product->get_id()),
            $images,
        );
        $product->set_image_id($ids[0] ?? '');
        $product->set_gallery_image_ids(array_slice($ids, 1));
    }

    /** @return list<WC_Product_Attribute> */
    private function attributes(Values $f): array
    {
        $attributes = [];
        foreach ($f->records('attributes') as $option) {
            $attributes[] = GlobalAttributes::attribute($option->string('name'), $option->strings('values'), $option->int('position'), true);
        }
        $type = $f->nullableString('product_type');
        if ($type !== null) {
            $attributes[] = GlobalAttributes::attribute(self::PRODUCT_TYPE_ATTRIBUTE, [$type], count($attributes) + 1, false);
        }

        return $attributes;
    }

    /**
     * Writes every variant as a variation and disables (never deletes) variations Shopify no longer has.
     *
     * @param list<Values> $variants
     */
    private function variations(int $productId, array $variants): void
    {
        $kept = [];
        foreach ($variants as $variant) {
            $gid = $variant->string('gid');
            $variation = new WC_Product_Variation($this->targets->links->targetId(Kind::Variant, $gid) ?? 0);
            $variation->set_parent_id($productId);
            $variation->set_status('publish');
            $variation->set_menu_order($variant->int('position'));
            $attributes = [];
            foreach ($variant->stringMap('options') as $name => $value) {
                $attributes[GlobalAttributes::taxonomyName($name)] = GlobalAttributes::valueSlug($name, $value);
            }
            $variation->set_attributes($attributes);
            $image = $variant->nullableString('image');
            $variation->set_image_id($image === null ? '' : $this->targets->media->attachment($image, null, $productId));
            $this->variantFields($variation, $variant, $gid);
            $id = $variation->save();
            $kept[] = $id;
            $this->targets->links->save(Kind::Variant, $gid, $id);
        }
        $product = wc_get_product($productId);
        foreach ($product instanceof WC_Product ? $product->get_children() : [] as $childId) {
            if (!in_array($childId, $kept, true)) {
                $orphan = new WC_Product_Variation($childId);
                $orphan->set_status('private');
                Retirement::mark(new WcDataMeta($orphan));
                $orphan->save();
            }
        }
    }

    /** The commerce fields of one Shopify variant, on a simple product or a variation. */
    private function variantFields(WC_Product $product, Values $variant, string $ownerGid): void
    {
        $this->unique($ownerGid, 'SKU', static fn() => $product->set_sku($variant->nullableString('sku') ?? ''));
        $gtin = $variant->nullableString('gtin');
        $this->unique($ownerGid, 'GTIN', static fn() => $product->set_global_unique_id($gtin ?? ''));
        $product->set_regular_price($variant->string('regular_price'));
        $product->set_sale_price($variant->nullableString('sale_price') ?? '');
        $product->set_virtual($variant->bool('virtual'));
        $product->set_tax_status($variant->bool('taxable') ? 'taxable' : 'none');
        $manageStock = $variant->bool('manage_stock');
        $product->set_manage_stock($manageStock);
        $product->set_backorders($variant->bool('backorders') ? 'yes' : 'no');
        if ($manageStock) {
            $product->set_stock_quantity($variant->nullableInt('stock_quantity') ?? 0);
        } else {
            $product->set_stock_status('instock');
        }
        $weight = $variant->record('weight');
        $product->set_weight($weight === null ? '' : (string) wc_get_weight(
            (float) $weight->string('value'),
            Options::string('woocommerce_weight_unit', self::DEFAULT_WEIGHT_UNIT),
            self::WEIGHT_UNITS[$weight->string('unit')] ?? self::DEFAULT_WEIGHT_UNIT,
        ));
        $cost = $variant->nullableString('cost');
        if (FeaturesUtil::feature_is_enabled(self::COST_OF_GOODS_FEATURE)) {
            $product->set_cogs_value($cost === null ? null : (float) $cost);
        }
        $meta = new WcDataMeta($product);
        MetaSync::fields($meta, [
            '_shopify_variant_gid' => $variant->string('gid'),
            '_shopify_gtin' => $gtin,
            '_shopify_unit_cost' => $cost,
            '_shopify_origin_country' => $variant->nullableString('origin_country'),
            '_shopify_origin_province' => $variant->nullableString('origin_province'),
            '_shopify_hs_code' => $variant->nullableString('hs_code'),
        ]);
        if ($product instanceof WC_Product_Variation) {
            MetaSync::metafields($meta, $variant->metafields());
        }
    }

    /** Sets a field WooCommerce requires to be unique; a clash is reported (and the field left empty). */
    private function unique(string $gid, string $field, callable $set): void
    {
        try {
            $set();
        } catch (WC_Data_Exception $e) {
            $this->targets->log->warning("{$gid}: {$field} not set: {$e->getMessage()}");
        }
    }

    /** Vendor → WooCommerce Brands (core since 9.6); it is also mirrored as meta. */
    private function brand(int $productId, ?string $brand): void
    {
        if (taxonomy_exists(self::BRAND_TAXONOMY)) {
            wp_set_object_terms($productId, $brand === null ? [] : Terms::idsByName(self::BRAND_TAXONOMY, [$brand]), self::BRAND_TAXONOMY);
        }
    }
}
