<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A product (`Product2`) → a simple WooCommerce product: name, description, its standard price book entry's unit
 * price, its stock keeping unit (else its product code) as the SKU; an inactive product is kept private.
 * Salesforce keeps no stock or images for products, so stock is unmanaged. Its family, code, quantity unit and
 * every other field a user can set are meta, as are its prices in other price books' entries the standard
 * entry doesn't show (see Planned on the coverage page).
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'salesforce-';

    private const VERSION = 1;
    private const NATIVE = ['Name', 'Description', 'StockKeepingUnit', 'IsActive', 'CreatedDate'];

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
        $title = $node->string('Name');
        $sku = Fields::text($node, 'StockKeepingUnit') ?? Fields::text($node, 'ProductCode');
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . $id;
        }
        $entry = $node->child('PricebookEntries')?->list('records')[0] ?? null;
        $price = $entry === null ? null : Fields::text($entry, 'UnitPrice');

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => Slugs::fromTitle($title),
            'description_html' => CrmContract::textHtml(Fields::text($node, 'Description')),
            'status' => Fields::flag($node, 'IsActive') ? 'publish' : 'private',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'CreatedDate'),
            'tags' => [],
            'collections' => [],
            'images' => [],
            'attributes' => [],
            'variants' => [[
                'gid' => Fields::id('Variant', $id),
                'title' => $title,
                'position' => 1,
                'sku' => $sku,
                'gtin' => null,
                'regular_price' => $price === null ? '0' : Decimal::of($price),
                'sale_price' => null,
                'cost' => null,
                'weight' => null,
                'virtual' => false,
                'taxable' => true,
                'manage_stock' => false,
                'stock_quantity' => null,
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
        ], [], $entry === null ? ["\"{$title}\" has no standard price book entry; its price is 0 until it has one."] : []);
    }
}
