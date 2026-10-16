<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\SquareLimits;
use IdempotentImporter\Mapping\VariantAttributes;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/**
 * A product → a WooCommerce product. Each variant becomes a variation identified by its Lightspeed id (see
 * Fields::variantId); a product with one variant and no matrix options is a simple product. A matrix
 * variant's options become attributes; a product whose several variants have no matrix options gets one
 * "Variant" attribute valued by each variant's title. Prices are taken with tax in a retail shop and without
 * in a B2B shop (as each shows them); an old price above the price makes the old price the regular price and
 * the price the sale price. Stock follows each variant's tracking (disabled: unmanaged; indicator: managed,
 * with backorders). Weights keep their unit. Hidden products are kept private. The images are in their sort
 * order; the brand becomes the WooCommerce brand; the old storefront URL (`/<url>.html`) redirects to it.
 * Lightspeed's other product and variant fields (long title, short description, template data, option set,
 * supplier, delivery date, article codes, unit prices, the prices on the other side of tax, stock limits,
 * dimensions, tax settings) are mirrored as meta. Stock sold is volatile and not mirrored.
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'lightspeed-';
    public const VARIANT_ATTRIBUTE = 'Variant';

    private const VERSION = 1;
    private const TRACKING_DISABLED = 'disabled';
    private const TRACKING_BACKORDERS = 'indicator';
    private const DETAILS = ['fulltitle', 'description', 'visibility', 'hasMatrix', 'data01', 'data02', 'data03', 'templateDataFields', 'set', 'type'];
    private const VARIANT_DETAILS = [
        'isDefault', 'sortOrder', 'articleCode', 'unitPrice', 'unitUnit', 'priceExcl', 'priceIncl', 'oldPriceExcl',
        'oldPriceIncl', 'stockTracking', 'stockAlert', 'stockMinimum', 'stockBuyMinimum', 'stockBuyMaximum', 'volume',
        'volumeValue', 'volumeUnit', 'colli', 'sizeXValue', 'sizeYValue', 'sizeZValue', 'sizeUnit', 'taxType',
        'allowWithdrawal',
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
        $title = $node->string('title');
        $variantNodes = $node->list('variants');
        if ($variantNodes === []) {
            throw new SourceError("Lightspeed product {$node->id()} has no variants.");
        }
        usort($variantNodes, static fn(Node $a, Node $b): int => [$a->optionalInt('sortOrder') ?? 0, $a->int('id')] <=> [$b->optionalInt('sortOrder') ?? 0, $b->int('id')]);
        $simple = count($variantNodes) === 1 && self::options($variantNodes[0]) === [];
        $includesTax = Fields::pricesIncludeTax($node);
        $variants = [];
        foreach ($variantNodes as $index => $variant) {
            $variants[] = $this->variant($title, $variant, $index + 1, $simple, $includesTax);
        }
        $url = $node->string('url');
        $warnings = SquareLimits::warnings($title, $variants);
        foreach ($variantNodes as $variant) {
            if (Fields::weightUnit(Fields::text($variant, 'weightUnit')) === null && !Decimal::isZero($variant->optionalDecimal('weightValue'))) {
                $warnings[] = "\"{$title}\" has a variant weighed in " . (Fields::text($variant, 'weightUnit') ?? 'no unit') . ', which WooCommerce has no unit for; its weight is meta.';
            }
        }

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => Fields::slug($url, $title),
            'description_html' => Fields::text($node, 'content') ?? '',
            'status' => ($node->raw()['isVisible'] ?? true) === false ? 'private' : 'publish',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'createdAt'),
            'tags' => [],
            'collections' => array_map(Fields::categoryId(...), $node->ints('category_ids')),
            'images' => self::images($node),
            'attributes' => $simple ? [] : VariantAttributes::fromVariants($variants),
            'variants' => $variants,
            'simple' => $simple,
            'seo' => ['title' => null, 'description' => null],
            'brand' => $node->optionalString('brand'),
            'supplier_id' => Fields::linkedId($node, 'supplier'),
            'delivery_date_id' => Fields::linkedId($node, 'deliverydate'),
            'product' => Fields::details($node, self::DETAILS),
            'variant_details' => array_combine(
                array_map(static fn(Node $variant): string => (string) $variant->int('id'), $variantNodes),
                array_map(static fn(Node $variant): array => [
                    ...Fields::details($variant, self::VARIANT_DETAILS),
                    'weight' => $variant->optionalDecimal('weightValue'),
                    'weight_unit' => Fields::text($variant, 'weightUnit'),
                    'tax_id' => Fields::linkedId($variant, 'tax'),
                ], $variantNodes),
            ),
        ], [Fields::productPath($url)], $warnings);
    }

    /** @return array<string, mixed> */
    private function variant(string $productTitle, Node $variant, int $position, bool $simple, bool $includesTax): array
    {
        $variantId = $variant->int('id');
        $options = $simple ? [] : self::options($variant);
        if (!$simple && $options === []) {
            $options = [self::VARIANT_ATTRIBUTE => Fields::text($variant, 'title') ?? (string) $variantId];
        }
        $sku = Fields::text($variant, 'sku');
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . $variantId;
        }
        [$regular, $sale] = self::prices($variant, $includesTax);
        $tracking = Fields::text($variant, 'stockTracking') ?? self::TRACKING_DISABLED;
        $manageStock = $tracking !== self::TRACKING_DISABLED;
        $unit = Fields::weightUnit(Fields::text($variant, 'weightUnit'));
        $weight = $variant->optionalDecimal('weightValue');
        $image = Fields::object($variant, 'image');

        return [
            'gid' => Fields::variantId($variantId),
            'title' => $options === [] ? $productTitle : implode(' / ', $options),
            'position' => $position,
            'sku' => $sku,
            'gtin' => Fields::text($variant, 'ean'),
            'regular_price' => $regular,
            'sale_price' => $sale,
            'cost' => Fields::setAmount($variant, 'priceCost'),
            'weight' => $unit === null || $weight === null || Decimal::isZero($weight) ? null : ['value' => Decimal::normalise($weight), 'unit' => $unit],
            'virtual' => false,
            'taxable' => true,
            'manage_stock' => $manageStock,
            'stock_quantity' => $manageStock ? $variant->optionalInt('stockLevel') ?? 0 : null,
            'backorders' => $tracking === self::TRACKING_BACKORDERS,
            'image' => $image?->string('src'),
            'options' => $options,
            'origin_country' => null,
            'origin_province' => null,
            'hs_code' => Fields::text($variant, 'hs'),
            'meta' => [],
        ];
    }

    /**
     * The regular and sale price: an old price above the price is the regular price, the price then on sale.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function prices(Node $variant, bool $includesTax): array
    {
        $price = $variant->optionalDecimal($includesTax ? 'priceIncl' : 'priceExcl')
            ?? throw new SourceError("Lightspeed variant {$variant->int('id')} has no price.");
        $old = Fields::setAmount($variant, $includesTax ? 'oldPriceIncl' : 'oldPriceExcl');

        return $old !== null && Decimal::compare($old, $price) > 0 ? [$old, $price] : [$price, null];
    }

    /**
     * A matrix variant's options, option name → chosen value, in the variant's order.
     *
     * @return array<string, string>
     */
    public static function options(Node $variant): array
    {
        $options = [];
        foreach (is_array($variant->raw()['options'] ?? null) ? $variant->list('options') : [] as $option) {
            $options[$option->string('name')] = $option->requiredChild('value')->string('name');
        }

        return $options;
    }

    /**
     * The product's images by sort order (else its one main image), at full size.
     *
     * @return list<array{url: string, alt: ?string}>
     */
    private static function images(Node $product): array
    {
        $images = $product->list('images');
        usort($images, static fn(Node $a, Node $b): int => [$a->optionalInt('sortOrder') ?? 0, $a->int('id')] <=> [$b->optionalInt('sortOrder') ?? 0, $b->int('id')]);
        $main = Fields::object($product, 'image');
        if ($images === [] && $main !== null) {
            $images = [$main];
        }

        return array_map(static fn(Node $image): array => ['url' => $image->string('src'), 'alt' => null], $images);
    }
}
