<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\QuickBooksOnline\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * An inventory, non-inventory or service item → a simple WooCommerce product: name, description, sales price, SKU
 * (optional generated `qbo-<id>`), purchase cost as the cost, taxable, quantity on hand as managed stock for an
 * inventory item that tracks it, its category (its parent, when that is a category), created time; a service is
 * virtual, an inactive item private. Its purchase description, accounts, tax codes, reorder point and preferred
 * vendor are meta. A bundle (`Group`) has no WooCommerce home without a bundles plugin: not mirrored.
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'qbo-';
    public const SERVICE = 'Service';
    public const INVENTORY = 'Inventory';
    public const BUNDLE = 'Group';

    private const VERSION = 1;
    private const DETAILS = [
        'Type', 'FullyQualifiedName', 'PurchaseDesc', 'IncomeAccountRef', 'ExpenseAccountRef', 'AssetAccountRef', 'SalesTaxCodeRef',
        'PurchaseTaxCodeRef', 'ReorderPoint', 'PrefVendorRef', 'InvStartDate', 'SalesTaxIncluded', 'Active',
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
        $type = Fields::text($node, 'Type');
        if ($type === self::BUNDLE) {
            throw new Unsupported('a bundle (QuickBooks group item) needs a WooCommerce bundles plugin; planned.');
        }
        $id = Fields::sourceId($node);
        $title = Fields::text($node, 'Name') ?? "Item {$id}";
        $sku = Fields::text($node, 'Sku') ?? ($this->generateMissingSkus ? self::GENERATED_SKU_PREFIX . $id : null);
        $tracked = $type === self::INVENTORY && ($node->raw()['TrackQtyOnHand'] ?? false) === true;
        $quantity = Fields::amount($node, 'QtyOnHand');
        $category = $node->optionalString('category_id');
        $cost = Fields::amount($node, 'PurchaseCost');

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => Slugs::fromTitle($title),
            'description_html' => CrmContract::textHtml(Fields::text($node, 'Description')),
            'status' => Fields::active($node) ? 'publish' : 'private',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::created($node),
            'tags' => [],
            'collections' => $category === null ? [] : [Fields::id('Category', $category)],
            'images' => [],
            'attributes' => [],
            'variants' => [[
                'gid' => Fields::id('Variant', $id),
                'title' => $title,
                'position' => 1,
                'sku' => $sku,
                'gtin' => null,
                'regular_price' => Decimal::normalise(Fields::amount($node, 'UnitPrice') ?? '0'),
                'sale_price' => null,
                'cost' => $cost === null || Decimal::isZero($cost) ? null : Decimal::normalise($cost),
                'weight' => null,
                'virtual' => $type === self::SERVICE,
                'taxable' => ($node->raw()['Taxable'] ?? true) !== false,
                'manage_stock' => $tracked,
                'stock_quantity' => $tracked ? (int) Decimal::round($quantity ?? '0', 0) : null,
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
