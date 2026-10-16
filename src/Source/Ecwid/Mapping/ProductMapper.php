<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Ecwid\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\SquareLimits;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\VariantAttributes;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A product → a WooCommerce product. A product without variations is a simple product; one with variations
 * (`combinations`) is a variable product whose variations are identified by their Ecwid ids (see
 * Fields::variantId) and whose attributes are the options each variation selects. A compare-to (pre-sale)
 * price above the price makes it the regular price and the price the sale price. Stock follows `unlimited`
 * (unlimited: unmanaged) and `quantity`; pre-orders (`ALLOW_PREORDER`) allow backorders. A product that
 * needs no shipping is virtual; one that isn't taxable is untaxed. Weights are in the store's unit (carats
 * have no WooCommerce unit: the weight is then meta). The UPC attribute is the GTIN, the brand attribute the
 * WooCommerce brand. Images are the media images, the main one first, at full size with their alt text. A
 * disabled product is kept private. The old storefront URL redirects to it. The subtitle, every attribute,
 * the options (with their price modifiers), wholesale prices, dimensions, shipping settings, files, purchase
 * limits, subscription and tax settings, related products and the other product and variation details are
 * mirrored as meta. A gift card is Unsupported. Favorites and review counts are volatile and not mirrored.
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'ecwid-';

    private const VERSION = 1;
    private const UPC = 'UPC';
    private const BRAND = 'BRAND';
    private const PREORDER = 'ALLOW_PREORDER';
    private const DETAILS = [
        'subtitle', 'attributes', 'options', 'wholesalePrices', 'dimensions', 'volume', 'shipping', 'files', 'tax',
        'defaultCategoryId', 'productClassId', 'warningLimit', 'minPurchaseQuantity', 'maxPurchaseQuantity',
        'outOfStockVisibilityBehaviour', 'subscriptionSettings', 'isSampleProduct', 'discountsAllowed',
        'externalReferenceId', 'showOnFrontpage', 'relatedProducts', 'compositeComponents', 'url', 'weight', 'sku',
    ];
    private const VARIATION_DETAILS = [
        'combinationNumber', 'attributes', 'wholesalePrices', 'dimensions', 'volume', 'warningLimit',
        'minPurchaseQuantity', 'maxPurchaseQuantity', 'outOfStockVisibilityBehaviour', 'weight',
    ];
    /** Option types whose choices a customer picks from (others take text, a date or files). */
    private const CHOICE_TYPES = ['SELECT', 'RADIO', 'CHECKBOX', 'SIZE', 'SWATCHES'];

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
        if (($node->raw()['isGiftCard'] ?? false) === true) {
            throw new Unsupported("\"{$title}\" is a gift card; WooCommerce sells gift cards only through a gift-card plugin.");
        }
        $productId = Fields::sourceId($node);
        $combinations = $node->list('combinations');
        usort($combinations, static fn(Node $a, Node $b): int => [$a->optionalInt('combinationNumber') ?? 0, $a->int('id')] <=> [$b->optionalInt('combinationNumber') ?? 0, $b->int('id')]);
        $simple = $combinations === [];
        $unit = Fields::weightUnit($node->optionalString('weight_unit'));
        $variants = $simple
            ? [$this->variant($node, $node, $productId, null, $title, $unit)]
            : array_map(fn(Node $combination): array => $this->variant($node, $combination, $productId, $combination->int('id'), $title, $unit), $combinations);

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => Fields::slug($node, $title),
            'description_html' => $node->optionalString('description') ?? '',
            'status' => ($node->raw()['enabled'] ?? true) === false ? 'private' : 'publish',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'created'),
            'tags' => [],
            'collections' => array_map(Fields::categoryId(...), $node->ints('categoryIds')),
            'images' => self::images($node),
            'attributes' => $simple ? [] : VariantAttributes::fromVariants($variants),
            'variants' => $variants,
            'simple' => $simple,
            'seo' => ['title' => $node->optionalString('seoTitle'), 'description' => $node->optionalString('seoDescription')],
            'brand' => Fields::attribute($node, self::BRAND),
            'product' => [...$node->only(self::DETAILS), 'weight_unit' => $node->optionalString('weight_unit')],
            'variation_details' => array_combine(
                array_map(static fn(Node $combination): string => (string) $combination->int('id'), $combinations),
                array_map(static fn(Node $combination): array => $combination->only(self::VARIATION_DETAILS), $combinations),
            ),
        ], Fields::legacyPaths($node->optionalString('url')), self::warnings($node, $title, $variants, $simple, $unit));
    }

    /**
     * One variant: the product itself (no variations) or one of its variations, which falls back to the
     * product for what it doesn't set (price, stock policy, weight, pre-orders).
     *
     * @return array<string, mixed>
     */
    private function variant(Node $product, Node $source, string $productId, ?int $combinationId, string $title, ?string $unit): array
    {
        $options = [];
        foreach ($combinationId === null ? [] : $source->list('options') as $option) {
            $options[$option->string('name')] = $option->string('value');
        }
        $sku = $source->optionalString('sku');
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . ($combinationId ?? $productId);
        }
        $price = $source->optionalDecimal('price') ?? $product->decimal('price');
        $compareTo = Fields::setAmount($source, 'compareToPrice');
        [$regular, $sale] = $compareTo !== null && Decimal::compare($compareTo, $price) > 0 ? [$compareTo, $price] : [$price, null];
        $unlimited = $source->raw()['unlimited'] ?? $product->raw()['unlimited'] ?? true;
        $weight = Fields::setAmount($source, 'weight') ?? ($combinationId === null ? null : Fields::setAmount($product, 'weight'));
        $outOfStock = $source->optionalString('outOfStockVisibilityBehaviour') ?? $product->optionalString('outOfStockVisibilityBehaviour');

        return [
            'gid' => Fields::variantId($productId, $combinationId),
            'title' => $options === [] ? $title : implode(' / ', $options),
            'position' => $source->optionalInt('combinationNumber') ?? 1,
            'sku' => $sku,
            'gtin' => Fields::attribute($source, self::UPC),
            'regular_price' => $regular,
            'sale_price' => $sale,
            'cost' => $combinationId === null ? Fields::setAmount($product, 'costPrice') : null,
            'weight' => $unit === null || $weight === null ? null : ['value' => Decimal::normalise($weight), 'unit' => $unit],
            'virtual' => ($product->raw()['isShippingRequired'] ?? true) === false,
            'taxable' => ($product->child('tax')?->raw()['taxable'] ?? true) !== false,
            'manage_stock' => $unlimited !== true,
            'stock_quantity' => $unlimited === true ? null : $source->optionalInt('quantity') ?? 0,
            'backorders' => $outOfStock === self::PREORDER,
            'image' => $combinationId === null ? null : $source->optionalString('originalImageUrl'),
            'options' => $options,
            'origin_country' => null,
            'origin_province' => null,
            'hs_code' => null,
            'meta' => [],
        ];
    }

    /**
     * The media images, the main one first, at full size with their alt text; else the main image alone.
     *
     * @return list<array{url: string, alt: ?string}>
     */
    private static function images(Node $product): array
    {
        $images = $product->child('media')?->list('images') ?? [];
        usort($images, static fn(Node $a, Node $b): int => self::isMain($b) <=> self::isMain($a));
        $mapped = array_map(static fn(Node $image): array => ['url' => $image->string('imageOriginalUrl'), 'alt' => $image->child('alt')?->optionalString('main')], $images);
        $main = $product->optionalString('originalImageUrl');

        return $mapped === [] && $main !== null ? [['url' => $main, 'alt' => null]] : $mapped;
    }

    private static function isMain(Node $image): bool
    {
        return ($image->raw()['isMain'] ?? false) === true;
    }

    /**
     * What WooCommerce can't hold of the product.
     *
     * @param list<array<string, mixed>> $variants
     *
     * @return list<string>
     */
    private static function warnings(Node $product, string $title, array $variants, bool $simple, ?string $unit): array
    {
        $warnings = SquareLimits::warnings($title, $variants);
        if ($unit === null && Fields::setAmount($product, 'weight') !== null) {
            $warnings[] = "\"{$title}\" is weighed in " . ($product->optionalString('weight_unit') ?? 'no unit') . ', which WooCommerce has no unit for; its weight is meta.';
        }
        $choices = array_filter(
            $simple && is_array($product->raw()['options'] ?? null) ? $product->list('options') : [],
            static fn(Node $option): bool => in_array($option->optionalString('type'), self::CHOICE_TYPES, true),
        );
        if ($choices !== []) {
            $warnings[] = "\"{$title}\" has options without variations (" . implode(', ', array_map(static fn(Node $option): string => $option->string('name'), $choices)) . '); WooCommerce has no product options without an add-on plugin, so they are meta.';
        }
        if (is_array($product->raw()['wholesalePrices'] ?? null) && $product->raw()['wholesalePrices'] !== []) {
            $warnings[] = "\"{$title}\" has wholesale price tiers; WooCommerce has no tiered prices without a plugin, so they are meta.";
        }

        return $warnings;
    }
}
