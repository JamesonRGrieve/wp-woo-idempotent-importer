<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Squarespace\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Mapping\SquareLimits;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\VariantAttributes;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/**
 * A product → a WooCommerce product. Physical and service products are a simple product (one variant with
 * no attributes) or a variable product whose variations are its variants, identified by their Squarespace
 * ids (see Fields::variantKey) with their attributes in the product's attribute order. A download has no
 * variants: it is a simple virtual product priced at the product level. Services are virtual. A variant's
 * sale price counts only while it is on sale; unlimited stock is untracked. Hidden products are kept
 * private. Images by their order (the first is featured), the store page as the product category, tags,
 * SEO title and description; the old product URL redirects to it. Gift cards are Unsupported. Each
 * variant's MPN and shipping dimensions, the product type, store page, URL slug, attribute names and
 * download file name are mirrored as meta.
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'squarespace-';

    private const VERSION = 1;
    private const DIGITAL = 'DIGITAL';
    private const SERVICE = 'SERVICE';
    private const GIFT_CARD = 'GIFT_CARD';
    private const DETAILS = ['type', 'storePageId', 'url', 'urlSlug', 'variantAttributes', 'digitalGood'];

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
        $title = $node->optionalString('name') ?? '';
        $type = $node->string('type');
        if ($type === self::GIFT_CARD) {
            throw new Unsupported("\"{$title}\" is a gift card; WooCommerce has no gift cards.");
        }
        $warnings = [];
        if ($type === self::DIGITAL) {
            $variants = [$this->variant($node, $node, null, 1, [])];
            $simple = true;
            $warnings[] = "\"{$title}\" is a download; Squarespace's API doesn't give its file, so it is mirrored as a virtual product without one.";
            $details = [];
        } else {
            $variantNodes = $node->list('variants');
            if ($variantNodes === []) {
                throw new SourceError("Squarespace product {$node->id()} has no variants.");
            }
            $names = $node->strings('variantAttributes');
            $options = array_map(static fn(Node $variant): array => self::options($variant, $names), $variantNodes);
            $simple = count($variantNodes) === 1 && $options[0] === [];
            $variants = [];
            $details = [];
            foreach ($variantNodes as $index => $variantNode) {
                $variant = $this->variant($node, $variantNode, $variantNode->string('id'), $index + 1, $simple ? [] : $options[$index]);
                $variants[] = $variant;
                $details[$variantNode->string('id')] = array_filter([
                    'mpn' => $variantNode->optionalString('mpn'),
                    'dimensions' => $variantNode->child('shippingMeasurements')?->child('dimensions')?->raw(),
                ], static fn(mixed $value): bool => $value !== null);
                $unit = $variantNode->child('shippingMeasurements')?->child('weight')?->optionalString('unit');
                if ($unit !== null && Fields::weightUnit($unit) === null) {
                    $warnings[] = "\"{$title}\" has a variant weighed in {$unit}, which has no WooCommerce unit; its weight is not mirrored.";
                }
            }
        }
        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => $node->optionalString('urlSlug') ?? Slugs::fromTitle($title),
            'description_html' => $node->optionalString('description') ?? '',
            'status' => ($node->raw()['isVisible'] ?? true) === false ? 'private' : 'publish',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'createdOn'),
            'tags' => $node->strings('tags'),
            'collections' => [Fields::storePageId($node->string('storePageId'))],
            'images' => self::images($node),
            'attributes' => $simple ? [] : VariantAttributes::fromVariants($variants),
            'variants' => $variants,
            'simple' => $simple,
            'seo' => ['title' => $node->child('seoOptions')?->optionalString('title'), 'description' => $node->child('seoOptions')?->optionalString('description')],
            'brand' => null,
            'product' => $node->only(self::DETAILS),
            'variant_details' => array_filter($details, static fn(array $detail): bool => $detail !== []),
        ], Fields::legacyPaths($node->optionalString('url')), [...SquareLimits::warnings($title, $variants), ...array_values(array_unique($warnings))]);
    }

    /**
     * One variant, or (for a download, $variantId null) the product itself as its only variant.
     *
     * @param array<string, string> $options
     *
     * @return array<string, mixed>
     */
    private function variant(Node $product, Node $variant, ?string $variantId, int $position, array $options): array
    {
        $productId = Fields::sourceId($product);
        $type = $product->string('type');
        $pricing = $variant->child('pricing') ?? throw new SourceError("Squarespace product {$product->id()} has a variant with no pricing.");
        $sku = $variant->optionalString('sku');
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . Fields::variantKey($productId, $variantId);
        }
        $stock = $variant->child('stock');
        $tracked = $stock !== null && ($stock->raw()['unlimited'] ?? false) !== true ? $stock : null;
        $manageStock = $tracked !== null;
        $weight = $variant->child('shippingMeasurements')?->child('weight');
        $weightValue = $weight?->optionalDecimal('value');
        $weightUnit = Fields::weightUnit($weight?->optionalString('unit'));

        return [
            'gid' => Fields::variantId($productId, $variantId),
            'title' => $options === [] ? ($product->optionalString('name') ?? '') : implode(' / ', $options),
            'position' => $position,
            'sku' => $sku,
            'gtin' => $variant->optionalString('gtin'),
            'regular_price' => Fields::money($pricing, 'basePrice') ?? throw new SourceError("Squarespace product {$product->id()} has a variant with no base price."),
            'sale_price' => ($pricing->raw()['onSale'] ?? false) === true ? Fields::money($pricing, 'salePrice') : null,
            'cost' => null,
            'weight' => $weightUnit === null || $weightValue === null || Decimal::isZero($weightValue) ? null : ['value' => $weightValue, 'unit' => $weightUnit],
            'virtual' => $type === self::DIGITAL || $type === self::SERVICE,
            'taxable' => true,
            'manage_stock' => $manageStock,
            'stock_quantity' => $tracked === null ? null : max(0, $tracked->optionalInt('quantity') ?? 0),
            'backorders' => false,
            'image' => $variantId === null ? null : $variant->child('image')?->optionalString('url'),
            'options' => $options,
            'origin_country' => null,
            'origin_province' => null,
            'hs_code' => null,
            'meta' => [],
        ];
    }

    /**
     * A variant's attributes, attribute name → value: in the product's attribute order, then any others.
     *
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    private static function options(Node $variant, array $names): array
    {
        $attributes = Fields::stringMap($variant, 'attributes');
        $ordered = [];
        foreach ($names as $name) {
            if (isset($attributes[$name])) {
                $ordered[$name] = $attributes[$name];
            }
        }

        return [...$ordered, ...array_diff_key($attributes, $ordered)];
    }

    /**
     * The product's images by their position (the first is the featured image).
     *
     * @return list<array{url: string, alt: ?string}>
     */
    private static function images(Node $product): array
    {
        $images = $product->list('images');
        usort($images, static fn(Node $a, Node $b): int => ($a->optionalInt('orderIndex') ?? 0) <=> ($b->optionalInt('orderIndex') ?? 0));
        $mapped = [];
        foreach ($images as $image) {
            $url = $image->optionalString('url');
            if ($url !== null) {
                $mapped[] = ['url' => $url, 'alt' => $image->optionalString('altText')];
            }
        }

        return $mapped;
    }
}
