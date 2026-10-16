<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Portions adapted from "S2W - Import Shopify to WooCommerce" 1.3.6 by VillaTheme
// (https://villatheme.com), Copyright 2019-2026 VillaTheme.com, licensed GPL-2.0-or-later and used here
// under GPL-3.0 as part of this AGPL-3.0-or-later work: the compare-at/price → regular/sale price rule
// and the option → variation attribute mapping. Changed: GraphQL input, exact decimals, pure mapping.

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\SquareLimits;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'shopify-';

    private const VERSION = 3;
    private const STATUS = ['ACTIVE' => 'publish', 'DRAFT' => 'draft', 'ARCHIVED' => 'private', 'UNLISTED' => 'publish'];
    private const HIDDEN_STATUS = 'UNLISTED';

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
        if ($node->bool('isGiftCard')) {
            throw new Unsupported('Gift card product: WooCommerce has no gift cards (see coverage/shopify.md).');
        }
        $status = $node->string('status');
        $metafields = Fields::metafields($node);
        $simple = $node->bool('hasOnlyDefaultVariant');
        $variants = array_map($this->variant(...), $node->list('variants'));
        usort($variants, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
        [$images, $otherMedia] = self::media($node);
        $warnings = SquareLimits::warnings($node->string('title'), $variants);

        return new Desired(Kind::Product, $node->id(), [
            'title' => $node->string('title'),
            'slug' => $node->string('handle'),
            'description_html' => $node->optionalString('descriptionHtml') ?? '',
            'status' => self::STATUS[$status] ?? 'draft',
            'catalog_visibility' => $status === self::HIDDEN_STATUS ? 'hidden' : 'visible',
            'published_at' => $node->optionalString('publishedAt'),
            'created_at' => $node->string('createdAt'),
            'tags' => $node->strings('tags'),
            'brand' => $node->optionalString('vendor'),
            'product_type' => $node->optionalString('productType'),
            'category' => $node->child('category')?->string('fullName'),
            'category_id' => $node->child('category')?->id(),
            'template_suffix' => $node->optionalString('templateSuffix'),
            'seo' => Fields::seo($node->child('seo'), $metafields),
            'collections' => Fields::ids($node, 'collections'),
            'images' => $images,
            'other_media' => $otherMedia,
            'attributes' => $simple ? [] : self::attributes($node),
            'variants' => $variants,
            'meta' => $metafields,
            'simple' => $simple,
        ], [LegacyPaths::product($node->string('handle'))], $warnings);
    }

    /**
     * Option name → ordered values; each becomes a global attribute used for variations.
     *
     * @return list<array{name: string, position: int, values: list<string>}>
     */
    private static function attributes(Node $product): array
    {
        $attributes = [];
        foreach ($product->list('options') as $option) {
            $attributes[] = [
                'name' => $option->string('name'),
                'position' => $option->int('position'),
                'values' => array_map(static fn(Node $value): string => $value->string('name'), $option->list('optionValues')),
            ];
        }
        usort($attributes, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function variant(Node $variant): array
    {
        [$regular, $sale] = self::prices($variant->decimal('price'), $variant->optionalDecimal('compareAtPrice'));
        $item = $variant->requiredChild('inventoryItem');
        $weight = $item->requiredChild('measurement')->child('weight');
        $sku = $variant->optionalString('sku');
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . $variant->string('legacyResourceId');
        }
        $options = [];
        foreach ($variant->list('selectedOptions') as $selected) {
            $options[$selected->string('name')] = $selected->string('value');
        }
        $image = $variant->list('media')[0] ?? null;

        return [
            'gid' => $variant->id(),
            'title' => $variant->string('title'),
            'position' => $variant->int('position'),
            'sku' => $sku,
            'gtin' => $variant->optionalString('barcode'),
            'regular_price' => $regular,
            'sale_price' => $sale,
            'cost' => $item->child('unitCost')?->decimal('amount'),
            'weight' => $weight === null ? null : [
                'value' => $weight->decimal('value'),
                'unit' => Fields::WEIGHT_UNITS[$weight->string('unit')] ?? Fields::DEFAULT_WEIGHT_UNIT,
            ],
            'virtual' => !$item->bool('requiresShipping'),
            'taxable' => $variant->bool('taxable'),
            'manage_stock' => $item->bool('tracked'),
            'stock_quantity' => $variant->optionalInt('inventoryQuantity'),
            'backorders' => $variant->string('inventoryPolicy') === 'CONTINUE',
            'image' => $image?->child('image')?->string('url'),
            'options' => $options,
            'origin_country' => $item->optionalString('countryCodeOfOrigin'),
            'origin_province' => $item->optionalString('provinceCodeOfOrigin'),
            'hs_code' => $item->optionalString('harmonizedSystemCode'),
            'meta' => Fields::metafields($variant),
        ];
    }

    /**
     * Shopify's selling price and compare-at price → WooCommerce regular and sale price. A compare-at
     * price above the price is the regular price with the price as the sale price; otherwise the price is
     * the regular price and there is no sale.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function prices(string $price, ?string $compareAt): array
    {
        if ($compareAt === null || Decimal::isZero($compareAt) || Decimal::compare($compareAt, $price) <= 0) {
            return [$price, null];
        }

        return [$compareAt, $price];
    }

    /**
     * Images (featured first, then gallery) and every other media item (video, 3D, external video).
     *
     * @return array{0: list<array{url: string, alt: ?string}>, 1: list<array{type: string, url: string}>}
     */
    private static function media(Node $product): array
    {
        $images = [];
        $other = [];
        foreach ($product->list('media') as $media) {
            $image = $media->child('image');
            if ($image !== null) {
                $images[] = ['url' => $image->string('url'), 'alt' => $image->optionalString('altText') ?? $media->optionalString('alt')];
                continue;
            }
            $url = $media->optionalString('originUrl') ?? ($media->list('sources')[0] ?? null)?->string('url');
            if ($url !== null) {
                $other[] = ['type' => $media->typename(), 'url' => $url];
            }
        }

        return [$images, $other];
    }
}
