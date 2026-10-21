<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\HubSpot\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A product of the product library → a simple WooCommerce product: name, description, unit price, SKU and
 * cost of goods. HubSpot keeps no stock, images or shipping data for its products, so stock is unmanaged.
 * Recurring billing (`hs_recurring_billing_period`) and tiered pricing (`hs_pricing_model`, its tiers) have no
 * WooCommerce home yet: they are meta, the product is warned about, and the selling-plans companion and tiered
 * prices are planned. Every other property the account can set is meta.
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'hubspot-';

    private const VERSION = 1;
    private const NATIVE = ['name', 'description', 'price', 'hs_sku', 'hs_cost_of_goods_sold', 'createdate'];

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
        $title = Fields::property($node, 'name') ?? "Product {$id}";
        $sku = Fields::property($node, 'hs_sku');
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . $id;
        }
        $price = Fields::property($node, 'price');
        $cost = Fields::property($node, 'hs_cost_of_goods_sold');
        $warnings = [];
        if (Fields::property($node, 'hs_recurring_billing_period') !== null) {
            $warnings[] = "\"{$title}\" bills on a recurring period; it is meta until the selling-plans companion (wp-woo-selling-plans) holds it.";
        }
        if (Fields::property($node, 'hs_pricing_model') !== null) {
            $warnings[] = "\"{$title}\" has tiered prices; the tiers are meta until tiered prices have a home.";
        }

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => Slugs::fromTitle($title),
            'description_html' => CrmContract::textHtml(Fields::property($node, 'description')),
            'status' => 'publish',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'createdate'),
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
                'cost' => $cost === null ? null : Decimal::of($cost),
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
        ], [], $warnings);
    }
}
