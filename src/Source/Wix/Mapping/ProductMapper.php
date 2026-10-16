<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\SquareLimits;
use IdempotentImporter\Mapping\VariantAttributes;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\Wix\Catalog;
use IdempotentImporter\Support\Decimal;

/**
 * A product (of a V3 or a V1 catalog) → a WooCommerce product. Each variant becomes a variation identified
 * by its product and variant ids (see Fields::variantId); a product without options is a simple product
 * (its one variant). A variant's compare-at (V3) or undiscounted (V1) price above its selling price is the
 * regular price and the selling price the sale price. Stock is managed where Wix tracks quantities (V3:
 * summed over the variant's inventory items; V1: the variant's stock), and pre-order is backorders. Hidden
 * products are kept private; images are the media items in order (the first is Wix's main media); the
 * brand becomes the WooCommerce brand; the old product page URL (/product-page/<slug>) redirects to it. A V3
 * product's weight is in its weight unit; a V1 product's weight has no unit in the API, so it is meta. Wix's
 * other product fields (handle, ribbons, info sections, options and modifiers, tax group, dimensions, price
 * per unit, subscriptions, purchase eligibility, custom text fields) are mirrored as meta. Derived and
 * volatile fields (price and cost ranges, availability, item counts, revision) are not, so a sale doesn't
 * rewrite the product.
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'wix-';
    public const PRODUCT_PAGE_PATH = '/product-page/';

    private const VERSION = 1;
    private const DIGITAL_V3 = 'DIGITAL';
    private const DIGITAL_V1 = 'digital';
    private const IMAGE_V3 = 'IMAGE';
    private const IMAGE_V1 = 'image';
    private const V3_DETAILS = [
        'handle', 'visibleInPos', 'ribbon', 'additionalRibbons', 'infoSections', 'taxGroupId', 'mainCategoryId', 'options',
        'modifiers', 'physicalProperties', 'digitalProperties', 'subscriptionDetails', 'purchaseEligibility', 'productType',
        'currency',
    ];
    private const V3_VARIANT_DETAILS = ['visible', 'physicalProperties', 'digitalProperties'];
    private const PREORDER_SETTINGS = ['enabled', 'message', 'limit'];
    private const V1_DETAILS = [
        'sku', 'weight', 'productType', 'ribbon', 'additionalInfoSections', 'customTextFields', 'manageVariants',
        'productOptions', 'discount', 'productDimensions', 'packageDimensions', 'pricePerUnitData', 'numericId',
        'inventoryItemId',
    ];
    private const V1_VARIANT_DETAILS = ['visible', 'weight', 'productDimensions', 'packageDimensions'];

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
        return Catalog::from($node->string('catalog')) === Catalog::V1 ? $this->v1($node) : $this->v3($node);
    }

    private function v3(Node $node): Desired
    {
        $title = $node->string('name');
        $productId = Fields::sourceId($node);
        $variantNodes = $node->list('variants');
        if ($variantNodes === []) {
            throw new SourceError("Wix product {$node->id()} has no variants.");
        }
        $simple = $node->list('options') === [];
        $physical = $node->child('physicalProperties');
        $unit = Fields::weightUnit($physical?->child('weightMeasurementUnitInfo')?->optionalString('weightMeasurementUnit'));
        $inventory = self::inventoryByVariant($node->list('inventory_items'));
        $variants = [];
        $details = [];
        foreach ($variantNodes as $index => $variant) {
            $variantId = $variant->string('variantId');
            $variants[] = $this->v3Variant($node, $productId, $variant, $index + 1, $simple, $unit, $inventory[$variantId] ?? []);
            $details[$variantId] = [
                ...$variant->only(self::V3_VARIANT_DETAILS),
                'preorder' => self::preorder($inventory[$variantId] ?? []),
            ];
        }
        $slug = $node->string('slug');
        $soldOut = 0;
        foreach ($variantNodes as $variant) {
            $items = $inventory[$variant->string('variantId')] ?? [];
            $untracked = array_filter($items, static fn(Node $item): bool => ($item->raw()['trackQuantity'] ?? false) === true) === [];
            if ($untracked && $items !== [] && array_filter($items, static fn(Node $item): bool => ($item->raw()['inStock'] ?? true) !== false) === []) {
                ++$soldOut;
            }
        }
        $warnings = self::warnings($title, $variants, self::hidden($variantNodes), $soldOut);
        if ($node->list('modifiers') !== []) {
            $warnings[] = "\"{$title}\" asks customers for modifiers; WooCommerce products can't (they are meta).";
        }
        if ($unit === null && array_filter($variantNodes, static fn(Node $variant): bool => !Decimal::isZero($variant->child('physicalProperties')?->optionalDecimal('weight'))) !== []) {
            $warnings[] = "\"{$title}\" has a weight in a unit WooCommerce has no equivalent of; its weight is meta.";
        }

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => $slug,
            'description_html' => $node->optionalString('plainDescription') ?? '',
            'status' => ($node->raw()['visible'] ?? true) === false ? 'private' : 'publish',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'createdDate'),
            'tags' => [],
            'collections' => array_map(
                static fn(Node $category): string => Fields::collectionId(Catalog::V3, $category->string('id')),
                $node->child('directCategoriesInfo')?->list('categories') ?? [],
            ),
            'images' => self::v3Images($node->child('media')),
            'attributes' => $simple ? [] : VariantAttributes::fromVariants($variants),
            'variants' => $variants,
            'simple' => $simple,
            'seo' => Fields::seo($node->child('seoData')),
            'brand' => $node->child('brand')?->optionalString('name'),
            'product' => $node->only(self::V3_DETAILS),
            'variant_details' => $details,
        ], [self::productPage($node->child('url')?->optionalString('relativePath'), $slug)], $warnings);
    }

    /**
     * @param list<Node> $inventory the variant's inventory items (one per location)
     *
     * @return array<string, mixed>
     */
    private function v3Variant(Node $product, string $productId, Node $variant, int $position, bool $simple, ?string $unit, array $inventory): array
    {
        $variantId = $variant->string('variantId');
        $options = [];
        foreach ($variant->list('optionChoices') as $choice) {
            $names = $choice->requiredChild('optionChoiceNames');
            $options[$names->string('optionName')] = $names->string('choiceName');
        }
        $price = $variant->requiredChild('price');
        $actual = Fields::money($price->child('actualPrice')) ?? throw new SourceError("Wix variant {$variantId} has no price.");
        $compareAt = Fields::money($price->child('compareAtPrice'));
        $onSale = $compareAt !== null && Decimal::compare($compareAt, $actual) > 0;
        $weight = $variant->child('physicalProperties')?->optionalDecimal('weight');
        $tracked = array_filter($inventory, static fn(Node $item): bool => ($item->raw()['trackQuantity'] ?? false) === true);
        $sku = $variant->optionalString('sku');
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . "{$productId}-{$variantId}";
        }

        return self::variant([
            'gid' => Fields::variantId($productId, $variantId),
            'title' => $simple || $options === [] ? $product->string('name') : implode(' / ', $options),
            'position' => $position,
            'sku' => $sku,
            'gtin' => $variant->optionalString('barcode'),
            'regular_price' => $onSale ? $compareAt : $actual,
            'sale_price' => $onSale ? $actual : null,
            'cost' => Fields::setAmount(Fields::money($variant->child('revenueDetails')?->child('cost'))),
            'weight' => $unit === null || $weight === null || Decimal::isZero($weight) ? null : ['value' => $weight, 'unit' => $unit],
            'virtual' => $product->optionalString('productType') === self::DIGITAL_V3,
            'manage_stock' => $tracked !== [],
            'stock_quantity' => $tracked === [] ? null : max(0, array_sum(array_map(static fn(Node $item): int => $item->optionalInt('quantity') ?? 0, $tracked))),
            'backorders' => array_filter($inventory, static fn(Node $item): bool => ($item->child('preorderInfo')?->raw()['enabled'] ?? false) === true) !== [],
            'image' => $variant->child('media')?->child('image')?->optionalString('url'),
            'options' => $simple ? [] : $options,
        ]);
    }

    private function v1(Node $node): Desired
    {
        $title = $node->string('name');
        $productId = Fields::sourceId($node);
        $variantNodes = $node->list('variants');
        if ($variantNodes === []) {
            throw new SourceError("Wix product {$node->id()} has no variants.");
        }
        $simple = $node->list('productOptions') === [];
        $variants = [];
        $details = [];
        foreach ($variantNodes as $index => $variant) {
            $variants[] = $this->v1Variant($node, $productId, $variant, $index + 1, $simple);
            $details[$variant->string('id')] = $variant->child('variant')?->only(self::V1_VARIANT_DETAILS) ?? [];
        }
        $slug = $node->string('slug');
        $soldOut = count(array_filter($variantNodes, static fn(Node $variant): bool => ($variant->child('stock')?->raw()['trackQuantity'] ?? false) !== true
            && ($variant->child('stock')?->raw()['inStock'] ?? true) === false));
        $warnings = self::warnings($title, $variants, self::hidden(array_values(array_filter(array_map(static fn(Node $variant): ?Node => $variant->child('variant'), $variantNodes)))), $soldOut);
        $weighed = array_filter($variantNodes, static fn(Node $variant): bool => !Decimal::isZero($variant->child('variant')?->optionalDecimal('weight') ?? $node->optionalDecimal('weight')));
        if ($weighed !== []) {
            $warnings[] = "\"{$title}\" has a weight, but Wix's V1 catalog doesn't say in which unit; its weight is meta.";
        }

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => $slug,
            'description_html' => $node->optionalString('description') ?? '',
            'status' => ($node->raw()['visible'] ?? true) === false ? 'private' : 'publish',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'createdDate'),
            'tags' => [],
            'collections' => array_map(static fn(string $id): string => Fields::collectionId(Catalog::V1, $id), $node->strings('collectionIds')),
            'images' => self::v1Images($node->child('media')),
            'attributes' => $simple ? [] : VariantAttributes::fromVariants($variants),
            'variants' => $variants,
            'simple' => $simple,
            'seo' => Fields::seo($node->child('seoData')),
            'brand' => $node->optionalString('brand'),
            'product' => $node->only(self::V1_DETAILS),
            'variant_details' => $details,
        ], [self::productPage($node->child('productPageUrl')?->optionalString('path'), $slug)], $warnings);
    }

    /** @return array<string, mixed> */
    private function v1Variant(Node $product, string $productId, Node $variant, int $position, bool $simple): array
    {
        $variantId = $variant->string('id');
        $data = $variant->child('variant');
        $priceData = $data?->child('priceData') ?? $product->child('priceData');
        $price = $priceData?->optionalDecimal('price') ?? throw new SourceError("Wix variant {$variantId} has no price.");
        $discounted = $priceData->optionalDecimal('discountedPrice');
        $onSale = $discounted !== null && Decimal::compare($discounted, $price) < 0;
        $stock = $variant->child('stock');
        $tracked = ($stock?->raw()['trackQuantity'] ?? false) === true;
        $options = self::choices($variant);
        $sku = $data?->optionalString('sku') ?? ($simple ? $product->optionalString('sku') : null);
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . "{$productId}-{$variantId}";
        }
        $cost = $data?->child('costAndProfitData')?->optionalDecimal('itemCost') ?? $product->child('costAndProfitData')?->optionalDecimal('itemCost');

        return self::variant([
            'gid' => Fields::variantId($productId, $variantId),
            'title' => $simple || $options === [] ? $product->string('name') : implode(' / ', $options),
            'position' => $position,
            'sku' => $sku,
            'gtin' => null,
            'regular_price' => $price,
            'sale_price' => $onSale ? $discounted : null,
            'cost' => Fields::setAmount($cost),
            'weight' => null,
            'virtual' => $product->optionalString('productType') === self::DIGITAL_V1,
            'manage_stock' => $tracked,
            'stock_quantity' => $tracked ? max(0, $stock->optionalInt('quantity') ?? 0) : null,
            'backorders' => false,
            'image' => null,
            'options' => $simple ? [] : $options,
        ]);
    }

    /**
     * A mapped variant with the fields Wix has no equivalent of.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function variant(array $fields): array
    {
        return [...$fields, 'taxable' => true, 'origin_country' => null, 'origin_province' => null, 'hs_code' => null, 'meta' => []];
    }

    /**
     * A V1 variant's choices, option name → choice, as Wix keys them.
     *
     * @return array<string, string>
     */
    private static function choices(Node $variant): array
    {
        $choices = $variant->raw()['choices'] ?? [];
        if (!is_array($choices)) {
            throw new SourceError("Wix variant {$variant->string('id')} has choices that aren't an object.");
        }
        $options = [];
        foreach ($choices as $name => $value) {
            if (!is_string($value)) {
                throw new SourceError("Wix variant {$variant->string('id')} has a choice that isn't text.");
            }
            $options[(string) $name] = $value;
        }

        return $options;
    }

    /**
     * Warnings shared by both catalogs: what WooCommerce Square can't sync, variants Wix hides (WooCommerce
     * sells them) and untracked variants Wix marks out of stock (WooCommerce keeps them in stock).
     *
     * @param list<array<string, mixed>> $variants mapped
     *
     * @return list<string>
     */
    private static function warnings(string $title, array $variants, int $hidden, int $soldOut): array
    {
        $warnings = SquareLimits::warnings($title, $variants);
        if ($hidden > 0) {
            $warnings[] = "\"{$title}\" has {$hidden} variant(s) Wix hides; WooCommerce sells them (see their meta).";
        }
        if ($soldOut > 0) {
            $warnings[] = "\"{$title}\" has {$soldOut} variant(s) Wix marks out of stock without tracking a quantity; WooCommerce keeps them in stock.";
        }

        return $warnings;
    }

    /** @param list<Node> $variants */
    private static function hidden(array $variants): int
    {
        return count(array_filter($variants, static fn(Node $variant): bool => ($variant->raw()['visible'] ?? true) === false));
    }

    /**
     * @param list<Node> $items
     *
     * @return array<string, list<Node>> inventory items by variant id
     */
    private static function inventoryByVariant(array $items): array
    {
        $byVariant = [];
        foreach ($items as $item) {
            $byVariant[$item->string('variantId')][] = $item;
        }

        return $byVariant;
    }

    /**
     * A variant's pre-order settings (its first location's), without the counters that change with every order.
     *
     * @param list<Node> $inventory
     *
     * @return array<array-key, mixed>|null
     */
    private static function preorder(array $inventory): ?array
    {
        return ($inventory[0] ?? null)?->child('preorderInfo')?->only(self::PREORDER_SETTINGS);
    }

    /**
     * Every image of a V3 product in Wix's order (the first is its main media); videos aren't images.
     *
     * @return list<array{url: string, alt: ?string}>
     */
    private static function v3Images(?Node $media): array
    {
        $items = $media?->child('itemsInfo')?->list('items') ?? [];
        if ($items === [] && $media?->child('main') !== null) {
            $items = [$media->requiredChild('main')];
        }
        $images = [];
        foreach ($items as $item) {
            $url = $item->child('image')?->optionalString('url');
            if ($url !== null && ($item->optionalString('mediaType') ?? self::IMAGE_V3) === self::IMAGE_V3) {
                $images[] = ['url' => $url, 'alt' => $item->optionalString('altText') ?? $item->requiredChild('image')->optionalString('altText')];
            }
        }

        return $images;
    }

    /**
     * Every image of a V1 product in Wix's order (the main media first when the items don't list it).
     *
     * @return list<array{url: string, alt: ?string}>
     */
    private static function v1Images(?Node $media): array
    {
        $items = $media?->list('items') ?? [];
        if ($items === [] && $media?->child('mainMedia') !== null) {
            $items = [$media->requiredChild('mainMedia')];
        }
        $images = [];
        foreach ($items as $item) {
            $image = $item->child('image');
            $url = $image?->optionalString('url');
            if ($url !== null && ($item->optionalString('mediaType') ?? self::IMAGE_V1) === self::IMAGE_V1) {
                $images[] = ['url' => $url, 'alt' => $image->optionalString('altText')];
            }
        }

        return $images;
    }

    /** The product's page path, else the default product page of its slug. */
    private static function productPage(?string $path, string $slug): string
    {
        return Fields::path($path) ?? Fields::path(self::PRODUCT_PAGE_PATH . $slug) ?? self::PRODUCT_PAGE_PATH . $slug;
    }
}
