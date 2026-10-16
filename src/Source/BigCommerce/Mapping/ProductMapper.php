<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\SquareLimits;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/**
 * A product → a WooCommerce product. Each variant becomes a variation identified by its BigCommerce id (see
 * Fields::variantId); a product whose only variant has no option values is a simple product (its base
 * variant). A variant's price, sale price, cost and weight fall back to the product's when BigCommerce
 * leaves them unset (null); a zero sale price or cost means none. Stock follows the product's inventory
 * tracking (none, the product, or each variant). Hidden products are kept private. The images are the
 * featured (thumbnail) image then the rest by sort order; the brand becomes the WooCommerce brand; the old
 * storefront URL redirects to it. BigCommerce's other product fields (custom fields, condition, availability,
 * pre-order and pricing settings, dimensions, warranty, gift wrapping, Open Graph and per-variant settings)
 * are mirrored as meta. Volatile statistics (views, sales and review counts) are not mirrored, so a sale or
 * a page view doesn't rewrite the product.
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'bigcommerce-';
    public const DIGITAL = 'digital';

    private const VERSION = 1;
    private const TRACK_NONE = 'none';
    private const TRACK_PRODUCT = 'product';
    private const TRACK_VARIANT = 'variant';
    private const DETAILS = [
        'sku', 'mpn', 'upc', 'gtin', 'type', 'condition', 'is_condition_shown', 'availability', 'availability_description',
        'preorder_release_date', 'preorder_message', 'is_preorder_only', 'is_price_hidden', 'price_hidden_label',
        'retail_price', 'map_price', 'tax_class_id', 'product_tax_code', 'warranty', 'search_keywords', 'meta_keywords',
        'is_featured', 'is_free_shipping', 'fixed_cost_shipping_price', 'width', 'depth', 'height',
        'order_quantity_minimum', 'order_quantity_maximum', 'gift_wrapping_options_type', 'gift_wrapping_options_list',
        'related_products', 'bulk_pricing_rules', 'bin_picking_number', 'layout_file', 'sort_order', 'brand_id',
        'inventory_tracking', 'inventory_level', 'inventory_warning_level', 'open_graph_type', 'open_graph_title',
        'open_graph_description', 'open_graph_use_meta_description', 'open_graph_use_product_name',
        'open_graph_use_image', 'videos', 'option_set_id', 'option_set_display', 'base_variant_id', 'channels',
    ];
    private const VARIANT_DETAILS = [
        'sku_id', 'mpn', 'upc', 'gtin', 'retail_price', 'width', 'height', 'depth', 'is_free_shipping',
        'fixed_cost_shipping_price', 'purchasing_disabled', 'purchasing_disabled_message', 'inventory_warning_level',
        'bin_picking_number',
    ];

    public function __construct(private readonly bool $generateMissingSkus) {}

    public function kind(): Kind
    {
        return Kind::Product;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $title = $node->string('name');
        $variantNodes = $node->list('variants');
        if ($variantNodes === []) {
            throw new SourceError("BigCommerce product {$node->id()} has no variants.");
        }
        $simple = count($variantNodes) === 1 && $variantNodes[0]->list('option_values') === [];
        $tracking = $node->optionalString('inventory_tracking') ?? self::TRACK_NONE;
        $weightUnit = Fields::weightUnit($node->optionalString('weight_units'));
        $variants = [];
        foreach ($variantNodes as $index => $variant) {
            $variants[] = $this->variant($node, $variant, $index + 1, $simple, $tracking, $weightUnit);
        }
        $url = $node->child('custom_url')?->optionalString('url');
        $warnings = SquareLimits::warnings($title, $variants);
        if (!$simple && $tracking === self::TRACK_PRODUCT) {
            $warnings[] = "\"{$title}\" tracks stock for the whole product; its variations are mirrored untracked (the product's stock is meta).";
        }
        if ($weightUnit === null && !Decimal::isZero($node->optionalDecimal('weight'))) {
            $warnings[] = "\"{$title}\" is weighed in {$node->optionalString('weight_units')}, which WooCommerce has no unit for; its weight is meta.";
        }
        $disabled = count(array_filter($variantNodes, static fn(Node $variant): bool => ($variant->raw()['purchasing_disabled'] ?? false) === true));
        if ($disabled > 0) {
            $warnings[] = "\"{$title}\" has {$disabled} variant(s) BigCommerce doesn't sell; WooCommerce sells them (see their meta).";
        }

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => Fields::slug($url, $title),
            'description_html' => $node->optionalString('description') ?? '',
            'status' => ($node->raw()['is_visible'] ?? true) === false ? 'private' : 'publish',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'date_created'),
            'tags' => [],
            'collections' => array_map(Fields::categoryId(...), self::categoryIds($node)),
            'images' => self::images($node),
            'attributes' => $simple ? [] : self::attributes($variants),
            'variants' => $variants,
            'simple' => $simple,
            'seo' => ['title' => $node->optionalString('page_title'), 'description' => $node->optionalString('meta_description')],
            'brand' => $node->optionalString('brand'),
            'weight' => $node->optionalDecimal('weight'),
            'weight_units' => $node->optionalString('weight_units'),
            'custom_fields' => array_map(static fn(Node $field): array => [
                'name' => $field->string('name'),
                'value' => $field->string('value'),
            ], $node->list('custom_fields')),
            'product' => Fields::details($node, self::DETAILS),
            'variant_details' => array_combine(
                array_map(static fn(Node $variant): string => (string) $variant->int('id'), $variantNodes),
                array_map(static fn(Node $variant): array => Fields::details($variant, self::VARIANT_DETAILS), $variantNodes),
            ),
        ], Fields::legacyPaths($url), $warnings);
    }

    /**
     * @return array<string, mixed>
     */
    private function variant(Node $product, Node $variant, int $position, bool $simple, string $tracking, ?string $weightUnit): array
    {
        $variantId = $variant->int('id');
        $options = $simple ? [] : self::options($variant);
        $sku = $variant->optionalString('sku') ?? ($simple ? $product->optionalString('sku') : null);
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . $variantId;
        }
        $manageStock = $tracking === self::TRACK_VARIANT || ($simple && $tracking === self::TRACK_PRODUCT);
        $stock = $tracking === self::TRACK_VARIANT ? $variant->optionalInt('inventory_level') : $product->optionalInt('inventory_level');
        $weight = $variant->optionalDecimal('weight') ?? $product->optionalDecimal('weight');
        $image = $variant->optionalString('image_url');

        return [
            'gid' => Fields::variantId($variantId),
            'title' => $options === [] ? $product->string('name') : implode(' / ', $options),
            'position' => $position,
            'sku' => $sku,
            'gtin' => $variant->optionalString('gtin') ?? $variant->optionalString('upc')
                ?? ($simple ? $product->optionalString('gtin') ?? $product->optionalString('upc') : null),
            'regular_price' => $variant->optionalDecimal('price') ?? $product->optionalDecimal('price')
                ?? throw new SourceError("BigCommerce variant {$variantId} has no price."),
            'sale_price' => Fields::setAmount($variant, 'sale_price') ?? ($variant->has('sale_price') ? null : Fields::setAmount($product, 'sale_price')),
            'cost' => Fields::setAmount($variant, 'cost_price') ?? ($variant->has('cost_price') ? null : Fields::setAmount($product, 'cost_price')),
            'weight' => $weightUnit === null || $weight === null || Decimal::isZero($weight) ? null : ['value' => $weight, 'unit' => $weightUnit],
            'virtual' => $product->optionalString('type') === self::DIGITAL,
            'taxable' => true,
            'manage_stock' => $manageStock,
            'stock_quantity' => $manageStock ? max(0, $stock ?? 0) : null,
            'backorders' => false,
            'image' => $image,
            'options' => $options,
            'origin_country' => null,
            'origin_province' => null,
            'hs_code' => null,
            'meta' => [],
        ];
    }

    /**
     * A variant's option values, option display name → value label, in the variant's order.
     *
     * @return array<string, string>
     */
    private static function options(Node $variant): array
    {
        $options = [];
        foreach ($variant->list('option_values') as $value) {
            $options[$value->string('option_display_name')] = $value->string('label');
        }

        return $options;
    }

    /**
     * Each attribute (in the order variants first use it) with every value it takes.
     *
     * @param list<array<string, mixed>> $variants
     *
     * @return list<array{name: string, position: int, values: list<string>}>
     */
    private static function attributes(array $variants): array
    {
        $attributes = [];
        foreach ($variants as $variant) {
            $options = $variant['options'];
            foreach (is_array($options) ? $options : [] as $name => $value) {
                $name = (string) $name;
                $attributes[$name] ??= ['name' => $name, 'position' => count($attributes) + 1, 'values' => []];
                if (is_string($value) && !in_array($value, $attributes[$name]['values'], true)) {
                    $attributes[$name]['values'][] = $value;
                }
            }
        }

        return array_values($attributes);
    }

    /** @return list<int> the product's category ids (BigCommerce sends them as JSON numbers) */
    private static function categoryIds(Node $product): array
    {
        $ids = [];
        $categories = $product->raw()['categories'] ?? [];
        foreach (is_array($categories) ? $categories : [] as $id) {
            $ids[] = match (true) {
                is_int($id) => $id,
                is_float($id) && $id === floor($id) => (int) $id,
                default => throw new SourceError("{$product->id()}.categories has a non-integer category id."),
            };
        }

        return array_values(array_unique($ids));
    }

    /**
     * The thumbnail image first, then the others by sort order; each at its largest size.
     *
     * @return list<array{url: string, alt: ?string}>
     */
    private static function images(Node $product): array
    {
        $images = $product->list('images');
        usort($images, static fn(Node $a, Node $b): int => [($b->raw()['is_thumbnail'] ?? false) === true, $a->optionalInt('sort_order') ?? 0, $a->optionalInt('id') ?? 0]
            <=> [($a->raw()['is_thumbnail'] ?? false) === true, $b->optionalInt('sort_order') ?? 0, $b->optionalInt('id') ?? 0]);
        $mapped = [];
        foreach ($images as $image) {
            $url = $image->optionalString('url_zoom') ?? $image->optionalString('url_standard') ?? $image->optionalString('image_url');
            if ($url !== null) {
                $mapped[] = ['url' => $url, 'alt' => $image->optionalString('description')];
            }
        }

        return $mapped;
    }
}
