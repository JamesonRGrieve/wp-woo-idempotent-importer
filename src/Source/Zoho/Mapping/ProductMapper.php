<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A product → a simple WooCommerce product: name, description, unit price, product code as the SKU, stock in
 * hand as managed stock, taxable or not, its category (a picklist value) as its product category; an inactive
 * product is kept private. Its vendor, manufacturer, taxes, unit, reorder level, commission rate and every other
 * field a user can set are meta.
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'zoho-';

    private const VERSION = 1;
    private const NATIVE = ['Product_Name', 'Description', 'Unit_Price', 'Product_Code', 'Qty_in_Stock', 'Taxable', 'Product_Active', 'Product_Category', 'Created_Time'];

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
        $id = Fields::sourceId($node);
        $title = Fields::text($node, 'Product_Name') ?? "Product {$id}";
        $sku = Fields::text($node, 'Product_Code');
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . $id;
        }
        $price = Fields::text($node, 'Unit_Price');
        $stock = Fields::text($node, 'Qty_in_Stock');
        $category = Fields::text($node, 'Product_Category');

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => Slugs::fromTitle($title),
            'description_html' => CrmContract::textHtml(Fields::text($node, 'Description')),
            'status' => ($node->raw()['Product_Active'] ?? true) === false ? 'private' : 'publish',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'Created_Time'),
            'tags' => [],
            'collections' => $category === null ? [] : [Fields::categoryId($category)],
            'images' => [],
            'attributes' => [],
            'variants' => [[
                'gid' => Fields::id('Variant', $id),
                'title' => $title,
                'position' => 1,
                'sku' => $sku,
                'gtin' => null,
                'regular_price' => $price === null ? '0' : Decimal::normalise($price),
                'sale_price' => null,
                'cost' => null,
                'weight' => null,
                'virtual' => false,
                'taxable' => ($node->raw()['Taxable'] ?? true) !== false,
                'manage_stock' => $stock !== null,
                'stock_quantity' => $stock === null ? null : (int) Decimal::normalise($stock),
                'backorders' => false,
                'image' => null,
                'options' => [],
                'origin_country' => null,
                'origin_province' => null,
                'hs_code' => null,
                'meta' => [],
            ]],
            'simple' => true,
            'seo' => ['title' => null, 'description' => null],
            'brand' => null,
            'product' => Fields::details($node, self::NATIVE),
        ]);
    }
}
