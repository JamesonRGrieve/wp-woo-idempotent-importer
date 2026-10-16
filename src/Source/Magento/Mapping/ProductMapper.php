<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Magento\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Mapping\SquareLimits;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\VariantAttributes;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Magento\MagentoReader;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/**
 * A product → a WooCommerce product. A simple, virtual or downloadable product is a simple product (virtual
 * for the latter two); a configurable product is a variable product whose variations are its enabled child
 * products on the store's website, identified by their product ids (see Fields::variantId), with the
 * configurable attributes' option labels as attribute values. Prices are the store's base prices; a special
 * price below the price is the sale price. Stock follows each product's stock item (Magento's defaults, stock
 * managed and no backorders, where it uses the configuration). A disabled product is kept private; Magento's
 * visibility (catalog, search, both) is WooCommerce's catalog visibility. Images are the enabled gallery images,
 * the base image first. Its url key is the slug and its old URL (url key + .html) redirects to it. Bundle,
 * grouped and gift card products have no exact WooCommerce equivalent (Unsupported); a product not on the
 * store's website maps to nothing. Custom options, tier prices, downloadable links, videos, the other
 * attributes and stock settings are mirrored as meta.
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'magento-';

    private const VERSION = 1;
    private const SIMPLE_TYPES = ['simple', 'virtual', 'downloadable'];
    private const VIRTUAL_TYPES = ['virtual', 'downloadable'];
    private const DISABLED = 2;
    private const IMAGE = 'image';
    private const NOT_TAXABLE = '0';
    /** Magento visibility → WooCommerce catalog visibility (1, "not visible individually", is a child product). */
    private const VISIBILITY = [2 => 'catalog', 3 => 'search', 4 => 'visible'];
    private const DETAILS = ['sku', 'type_id', 'attribute_set_id', 'status', 'visibility', 'updated_at', 'options', 'tier_prices', 'product_links'];
    private const EXTENSION_DETAILS = [
        'website_ids', 'category_links', 'stock_item', 'configurable_product_options', 'configurable_product_links',
        'downloadable_product_links', 'downloadable_product_samples',
    ];
    /** Attributes mapped to WooCommerce fields; the others are mirrored as meta. */
    private const MAPPED_ATTRIBUTES = ['description', 'url_key', 'meta_title', 'meta_description', 'special_price', 'cost', 'category_ids'];

    public function __construct(private readonly bool $generateMissingSkus) {}

    public function kind(): Kind
    {
        return Kind::Product;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): ?Desired
    {
        $title = $node->optionalString('name') ?? $node->string('sku');
        $type = $node->string('type_id');
        $store = $node->requiredChild('store');
        if (!self::onWebsite($node, $store)) {
            return null;
        }
        $simple = in_array($type, self::SIMPLE_TYPES, true);
        if (!$simple && $type !== MagentoReader::CONFIGURABLE) {
            throw new Unsupported("\"{$title}\" is a Magento {$type} product, which has no exact WooCommerce equivalent.");
        }
        $weightUnit = Fields::weightUnit($store->optionalString('weight_unit'));
        $variants = $simple
            ? [$this->variant($node, $node, (int) Fields::sourceId($node), $title, 1, $weightUnit, [])]
            : $this->children($node, $title, $weightUnit);
        if ($variants === []) {
            throw new Unsupported("\"{$title}\" is a configurable product with no enabled child products, so nothing of it can be sold.");
        }
        $attributes = Fields::customAttributes($node);
        $urlKey = Fields::attribute($attributes, 'url_key');
        $extension = $node->child('extension_attributes');

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => $urlKey ?? Slugs::fromTitle($title),
            'description_html' => Fields::attribute($attributes, 'description') ?? '',
            'status' => $node->optionalInt('status') === self::DISABLED ? 'private' : 'publish',
            'catalog_visibility' => self::VISIBILITY[$node->optionalInt('visibility') ?? 0] ?? 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'created_at'),
            'tags' => [],
            'collections' => array_map(Fields::categoryId(...), self::categoryIds($node, $store)),
            'images' => self::images($node, $store->string('media_url')),
            'attributes' => $simple ? [] : VariantAttributes::fromVariants($variants),
            'variants' => $variants,
            'simple' => $simple,
            'seo' => ['title' => Fields::attribute($attributes, 'meta_title'), 'description' => Fields::attribute($attributes, 'meta_description')],
            'brand' => null,
            'weight' => $node->optionalDecimal('weight'),
            'weight_unit' => $store->optionalString('weight_unit'),
            'custom_attributes' => array_diff_key($attributes, array_flip(self::MAPPED_ATTRIBUTES)),
            'product' => [...Fields::details($node, self::DETAILS), ...($extension === null ? [] : Fields::details($extension, self::EXTENSION_DETAILS))],
            'variant_details' => $simple ? [] : self::childDetails($node),
        ], Fields::legacyPaths($urlKey), self::warnings($node, $title, $variants, $weightUnit));
    }

    /**
     * The enabled child products on the store's website, each with its configurable attributes' option labels.
     *
     * @return list<array<string, mixed>>
     */
    private function children(Node $product, string $title, ?string $weightUnit): array
    {
        $store = $product->requiredChild('store');
        $attributes = [];
        foreach ($product->list('attributes') as $attribute) {
            $attributes[$attribute->int('attribute_id')] = $attribute;
        }
        $options = $product->requiredChild('extension_attributes')->list('configurable_product_options');
        usort($options, static fn(Node $a, Node $b): int => ($a->optionalInt('position') ?? 0) <=> ($b->optionalInt('position') ?? 0));
        $variants = [];
        foreach ($product->list('children') as $child) {
            if ($child->optionalInt('status') === self::DISABLED || !self::onWebsite($child, $store)) {
                continue;
            }
            $values = Fields::customAttributes($child);
            $chosen = [];
            foreach ($options as $option) {
                $attribute = $attributes[(int) $option->string('attribute_id')] ?? throw new SourceError("{$product->id()} names attribute {$option->string('attribute_id')}, which wasn't read.");
                $label = $option->optionalString('label') ?? $attribute->optionalString('default_frontend_label') ?? $attribute->string('attribute_code');
                $chosen[$label] = self::optionLabel($attribute, Fields::attribute($values, $attribute->string('attribute_code')), $child);
            }
            $variants[] = $this->variant($product, $child, $child->int('id'), $title, count($variants) + 1, $weightUnit, $chosen);
        }

        return $variants;
    }

    /**
     * One variant: the product itself (simple) or one of its child products (configurable), $id being the
     * Magento id of the product sold.
     *
     * @param array<string, string> $options
     *
     * @return array<string, mixed>
     */
    private function variant(Node $product, Node $sold, int $id, string $title, int $position, ?string $weightUnit, array $options): array
    {
        $attributes = Fields::customAttributes($sold);
        $price = $sold->optionalDecimal('price') ?? throw new SourceError("Magento product {$id} has no price.");
        $special = Fields::attribute($attributes, 'special_price');
        $stock = $sold->child('extension_attributes')?->child('stock_item');
        $manageStock = $stock !== null && (($stock->raw()['use_config_manage_stock'] ?? true) === true || ($stock->raw()['manage_stock'] ?? false) === true);
        $weight = $sold->optionalDecimal('weight') ?? $product->optionalDecimal('weight');
        $virtual = in_array($sold->optionalString('type_id'), self::VIRTUAL_TYPES, true);
        $sku = $sold->string('sku');
        $images = self::images($sold, $product->requiredChild('store')->string('media_url'));

        return [
            'gid' => Fields::variantId($id),
            'title' => $options === [] ? $title : implode(' / ', $options),
            'position' => $position,
            'sku' => $sku !== '' ? $sku : ($this->generateMissingSkus ? self::GENERATED_SKU_PREFIX . $id : null),
            'gtin' => null,
            'regular_price' => $price,
            'sale_price' => $special !== null && is_numeric($special) && Decimal::compare(Decimal::of($special), $price) < 0 ? Decimal::of($special) : null,
            'cost' => self::cost($attributes),
            'weight' => $virtual || $weightUnit === null || $weight === null || Decimal::isZero($weight) ? null : ['value' => $weight, 'unit' => $weightUnit],
            'virtual' => $virtual,
            'taxable' => Fields::attribute($attributes, 'tax_class_id') !== self::NOT_TAXABLE,
            'manage_stock' => $manageStock,
            'stock_quantity' => $stock === null || !$manageStock ? null : max(0, Fields::quantity($stock, 'qty')),
            'backorders' => $stock !== null && ($stock->raw()['use_config_backorders'] ?? true) !== true && ($stock->optionalInt('backorders') ?? 0) > 0,
            'image' => $options === [] ? null : $images[0]['url'] ?? null,
            'options' => $options,
            'origin_country' => Fields::attribute($attributes, 'country_of_manufacture'),
            'origin_province' => null,
            'hs_code' => null,
            'meta' => [],
        ];
    }

    /** The label of the option a child product has for an attribute (the child stores the option's value id). */
    private static function optionLabel(Node $attribute, ?string $value, Node $child): string
    {
        foreach ($attribute->list('options') as $option) {
            if ($value !== null && $option->string('value') === $value) {
                return $option->string('label');
            }
        }
        throw new SourceError("Magento product {$child->int('id')} has no {$attribute->string('attribute_code')} option that its configurable product offers.");
    }

    /** @param array<string, mixed> $attributes */
    private static function cost(array $attributes): ?string
    {
        $cost = Fields::attribute($attributes, 'cost');

        return $cost === null || !is_numeric($cost) || Decimal::isZero(Decimal::of($cost)) ? null : Decimal::of($cost);
    }

    private static function onWebsite(Node $product, Node $store): bool
    {
        $websites = $product->child('extension_attributes')?->ints('website_ids');

        return $websites === null || in_array($store->int('website_id'), $websites, true);
    }

    /** @return list<int> the product's categories, without the store roots */
    private static function categoryIds(Node $product, Node $store): array
    {
        $roots = $store->ints('root_category_ids');
        $ids = [];
        foreach ($product->child('extension_attributes')?->list('category_links') ?? [] as $link) {
            $id = (int) $link->string('category_id');
            if (!in_array($id, $roots, true)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The enabled gallery images, the base image first, then by position.
     *
     * @return list<array{url: string, alt: ?string}>
     */
    private static function images(Node $product, string $mediaUrl): array
    {
        $entries = array_values(array_filter(
            $product->list('media_gallery_entries'),
            static fn(Node $entry): bool => $entry->string('media_type') === self::IMAGE && ($entry->raw()['disabled'] ?? false) !== true && $entry->optionalString('file') !== null,
        ));
        usort($entries, static fn(Node $a, Node $b): int => [!in_array(self::IMAGE, $a->strings('types'), true), $a->optionalInt('position') ?? 0]
            <=> [!in_array(self::IMAGE, $b->strings('types'), true), $b->optionalInt('position') ?? 0]);

        return array_map(static fn(Node $entry): array => [
            'url' => Fields::productMediaUrl($mediaUrl, $entry->string('file')),
            'alt' => $entry->optionalString('label'),
        ], $entries);
    }

    /** @return array<int, array<array-key, mixed>> child product id → its SKU, status, attributes and stock */
    private static function childDetails(Node $product): array
    {
        $details = [];
        foreach ($product->list('children') as $child) {
            $details[$child->int('id')] = [
                ...Fields::details($child, ['sku', 'status', 'visibility', 'weight', 'updated_at']),
                'stock_item' => $child->child('extension_attributes')?->child('stock_item')?->raw(),
                'custom_attributes' => Fields::customAttributes($child),
            ];
        }

        return $details;
    }

    /**
     * @param list<array<string, mixed>> $variants
     *
     * @return list<string>
     */
    private static function warnings(Node $product, string $title, array $variants, ?string $weightUnit): array
    {
        $warnings = SquareLimits::warnings($title, $variants);
        if ($product->list('options') !== []) {
            $warnings[] = "\"{$title}\" has custom options; WooCommerce has no equivalent, so they are meta.";
        }
        if ($product->list('tier_prices') !== []) {
            $warnings[] = "\"{$title}\" has tier or customer-group prices; WooCommerce has one price, so they are meta.";
        }
        $attributes = Fields::customAttributes($product);
        if (Fields::attribute($attributes, 'special_from_date') !== null || Fields::attribute($attributes, 'special_to_date') !== null) {
            $warnings[] = "\"{$title}\" has a special price with dates; the sale price is mirrored without them (they are meta).";
        }
        if (($product->child('extension_attributes')?->list('downloadable_product_links') ?? []) !== []) {
            $warnings[] = "\"{$title}\" is downloadable; its files aren't mirrored (its links are meta).";
        }
        if ($weightUnit === null && !Decimal::isZero($product->optionalDecimal('weight'))) {
            $warnings[] = "\"{$title}\" is weighed in a unit WooCommerce has no equivalent of; its weight is meta.";
        }

        return $warnings;
    }
}
