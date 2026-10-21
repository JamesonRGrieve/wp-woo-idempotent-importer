<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\SquareLimits;
use IdempotentImporter\Mapping\VariantAttributes;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A product with its SKUs → a WooCommerce product. A product without variant options (one SKU) is a simple
 * product; one with options (`sku-properties`) is a variable product whose variations are its SKUs, identified
 * by their Webflow ids (what an order item names), with the option values each SKU selects as attributes.
 * Prices are in the currency's minor unit; a compare-at price above the price makes it the regular price and
 * the price the sale price. Stock is each SKU's inventory: finite inventory is managed at its quantity, infinite
 * inventory is unmanaged. A product that isn't shippable, or is digital or a service, is virtual; the
 * `standard-exempt` tax category is untaxed. Images are the default SKU's main image and its more images; each
 * variation has its own main image. Drafts are drafts and archived products private. The old product URL
 * (`/product/<slug>`) redirects to it. The tax category, product type, custom fields and each SKU's other
 * fields (including subscription billing) are meta.
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'webflow-';

    private const VERSION = 1;
    private const URL_BASE = '/product/';
    private const UNTAXED = 'standard-exempt';
    private const VIRTUAL_TYPES = ['digital', 'service'];
    private const SUBSCRIPTION = 'subscription';
    private const MORE_IMAGES = 'more-images';
    private const PRODUCT_CONSUMED = ['name', 'slug', 'description', 'category', 'sku-properties', 'default-sku'];
    private const SKU_CONSUMED = ['name', 'slug', 'price', 'compare-at-price', 'main-image', self::MORE_IMAGES, 'sku', 'sku-values', 'sku-properties'];

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
        $product = $node->requiredChild('fieldData');
        $title = $product->string('name');
        $slug = $product->string('slug');
        $properties = $product->list('sku-properties');
        $skus = $node->list('skus');
        $simple = $properties === [];
        $type = Fields::PRODUCT_TYPES[$product->optionalString('ec-product-type') ?? ''] ?? null;
        $virtual = ($product->raw()['shippable'] ?? true) === false || in_array($type, self::VIRTUAL_TYPES, true);
        $taxable = $product->optionalString('tax-category') !== self::UNTAXED;
        $variants = [];
        foreach ($skus as $position => $sku) {
            $variants[] = $this->variant($sku, $position + 1, $properties, $title, $virtual, $taxable);
        }
        $default = self::defaultSku($skus, $product->optionalString('default-sku'));

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => $slug,
            'description_html' => $product->optionalString('description') ?? '',
            'status' => Fields::status($node),
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'createdOn'),
            'tags' => [],
            'collections' => array_map(Fields::categoryId(...), $product->strings('category')),
            'images' => $default === null ? [] : self::images($default),
            'attributes' => $simple ? [] : VariantAttributes::fromVariants($variants),
            'variants' => $simple ? array_slice($variants, 0, 1) : $variants,
            'simple' => $simple,
            'seo' => ['title' => null, 'description' => null],
            'brand' => null,
            'product' => [...array_diff_key($product->raw(), array_flip(self::PRODUCT_CONSUMED)), 'product_type' => $type],
            'sku_details' => array_combine(
                array_map(static fn(Node $sku): string => Fields::sourceId($sku), $skus),
                array_map(static fn(Node $sku): array => array_diff_key($sku->requiredChild('fieldData')->raw(), array_flip(self::SKU_CONSUMED)), $skus),
            ),
        ], [self::URL_BASE . $slug], self::warnings($title, $variants, $skus));
    }

    /**
     * One SKU as a variant.
     *
     * @param list<Node> $properties
     *
     * @return array<string, mixed>
     */
    private function variant(Node $sku, int $position, array $properties, string $title, bool $virtual, bool $taxable): array
    {
        $data = $sku->requiredChild('fieldData');
        $skuId = Fields::sourceId($sku);
        $options = self::options($data->child('sku-values'), $properties);
        $price = Fields::money($data->requiredChild('price'));
        $compareAt = $data->child('compare-at-price') === null ? null : Fields::money($data->child('compare-at-price'));
        [$regular, $sale] = $compareAt !== null && Decimal::compare($compareAt, $price) > 0 ? [$compareAt, $price] : [$price, null];
        $inventory = $sku->child('inventory');
        $finite = $inventory !== null && $inventory->optionalString('inventoryType') === 'finite';
        $code = $data->optionalString('sku');
        if ($code === null && $this->generateMissingSkus) {
            $code = self::GENERATED_SKU_PREFIX . $skuId;
        }

        return [
            'gid' => Fields::variantId($skuId),
            'title' => $options === [] ? $title : implode(' / ', $options),
            'position' => $position,
            'sku' => $code,
            'gtin' => null,
            'regular_price' => $regular,
            'sale_price' => $sale,
            'cost' => null,
            'weight' => null,
            'virtual' => $virtual,
            'taxable' => $taxable,
            'manage_stock' => $finite,
            'stock_quantity' => $finite ? $inventory->optionalInt('quantity') ?? 0 : null,
            'backorders' => false,
            'image' => self::url($data, 'main-image'),
            'options' => $options,
            'origin_country' => null,
            'origin_province' => null,
            'hs_code' => null,
            'meta' => [],
        ];
    }

    /**
     * The option values a SKU selects, by option name (`sku-values` maps a property id to a value id).
     *
     * @param list<Node> $properties
     *
     * @return array<string, string>
     */
    private static function options(?Node $values, array $properties): array
    {
        $options = [];
        foreach ($properties as $property) {
            $chosen = $values?->optionalString($property->string('id'));
            foreach ($property->list('enum') as $value) {
                if ($chosen !== null && $value->string('id') === $chosen) {
                    $options[$property->string('name')] = $value->string('name');
                }
            }
        }

        return $options;
    }

    /** @param list<Node> $skus */
    private static function defaultSku(array $skus, ?string $defaultId): ?Node
    {
        foreach ($skus as $sku) {
            if (Fields::sourceId($sku) === $defaultId) {
                return $sku;
            }
        }

        return $skus[0] ?? null;
    }

    /** @return list<array{url: string, alt: ?string}> the SKU's main image, then its more images */
    private static function images(Node $sku): array
    {
        $data = $sku->requiredChild('fieldData');
        $images = [];
        $main = self::url($data, 'main-image');
        if ($main !== null) {
            $images[] = ['url' => $main, 'alt' => null];
        }
        foreach (is_array($data->raw()[self::MORE_IMAGES] ?? null) ? $data->list(self::MORE_IMAGES) : [] as $image) {
            $images[] = ['url' => $image->string('url'), 'alt' => $image->optionalString('alt')];
        }

        return $images;
    }

    /** An image field's URL: Webflow sends a URL, or an image object `{fileId, url, alt}`. */
    private static function url(Node $data, string $field): ?string
    {
        $value = $data->raw()[$field] ?? null;

        return is_array($value) ? $data->requiredChild($field)->optionalString('url') : $data->optionalString($field);
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @param list<Node> $skus
     *
     * @return list<string>
     */
    private static function warnings(string $title, array $variants, array $skus): array
    {
        $warnings = SquareLimits::warnings($title, $variants);
        foreach ($skus as $sku) {
            if ($sku->requiredChild('fieldData')->optionalString('ec-sku-billing-method') === self::SUBSCRIPTION) {
                $warnings[] = "\"{$title}\" is sold by subscription; its plan is meta until the selling-plans companion (wp-woo-selling-plans) holds it.";
                break;
            }
        }

        return $warnings;
    }
}
