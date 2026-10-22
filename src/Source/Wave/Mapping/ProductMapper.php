<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wave\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A product or service → a simple WooCommerce product: name, description, unit price, created time. Wave counts
 * no stock, so stock is unmanaged; it keeps no SKU (optional generated `wave-<id>`). A product Wave doesn't sell (one
 * it only buys) or an archived one is kept private. Whether it is sold and bought, its default sales taxes and its
 * income and expense accounts are meta.
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'wave-';

    private const VERSION = 1;
    private const DETAILS = ['isSold', 'isBought', 'isArchived', 'defaultSalesTaxes', 'incomeAccount', 'expenseAccount', 'modifiedAt', 'wave_id'];

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
        $id = Fields::recordId($node->string('wave_id'));
        $title = Fields::text($node, 'name') ?? "Product {$id}";
        $public = $node->bool('isSold') && !$node->bool('isArchived');

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => Slugs::fromTitle($title),
            'description_html' => CrmContract::textHtml(Fields::text($node, 'description')),
            'status' => $public ? 'publish' : 'private',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'createdAt'),
            'tags' => [],
            'collections' => [],
            'images' => [],
            'attributes' => [],
            'variants' => [[
                'gid' => Fields::id('Variant', $id),
                'title' => $title,
                'position' => 1,
                'sku' => $this->generateMissingSkus ? self::GENERATED_SKU_PREFIX . $id : null,
                'gtin' => null,
                'regular_price' => Decimal::normalise(Fields::text($node, 'unitPrice') ?? '0'),
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
            'product' => Fields::details($node, self::DETAILS),
        ]);
    }
}
