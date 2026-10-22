<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\FreshBooks\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * An item or a service (the reader says which, `record_type`) → a simple WooCommerce product.
 *
 * - An item: name, description, unit cost as the price, SKU (optional generated `freshbooks-<id>`), inventory as
 *   managed stock (none: unmanaged). Its default taxes, quantity multiplier and accounting id are meta.
 * - A service: name and hourly rate as the price, a virtual product. Whether it is billable is meta.
 *
 * An archived item or service is kept private.
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'freshbooks-';
    public const SERVICE = 'Service';

    private const VERSION = 1;
    private const ITEM_DETAILS = ['qty', 'tax1', 'tax2', 'accounting_systemid', 'updated'];
    private const SERVICE_DETAILS = ['billable'];

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
        $service = $node->string('record_type') === self::SERVICE;
        $title = Fields::text($node, 'name') ?? ($service ? "Service {$id}" : "Item {$id}");
        $sku = $service ? null : Fields::text($node, 'sku');
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . ($service ? 'service-' : '') . $id;
        }
        $price = $service ? $node->child('rate')?->optionalDecimal('rate') : Fields::money($node, 'unit_cost');
        $stock = $service ? null : Fields::text($node, 'inventory');

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => Slugs::fromTitle($title),
            'description_html' => CrmContract::textHtml(Fields::text($node, 'description')),
            'status' => $node->optionalInt('vis_state') === Fields::ARCHIVED ? 'private' : 'publish',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => null,
            'tags' => [],
            'collections' => [],
            'images' => [],
            'attributes' => [],
            'variants' => [[
                'gid' => Fields::id($service ? 'ServiceVariant' : 'ItemVariant', $id),
                'title' => $title,
                'position' => 1,
                'sku' => $sku,
                'gtin' => null,
                'regular_price' => $price === null ? '0' : Decimal::normalise($price),
                'sale_price' => null,
                'cost' => null,
                'weight' => null,
                'virtual' => $service,
                'taxable' => true,
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
            'product' => Fields::details($node, $service ? self::SERVICE_DETAILS : self::ITEM_DETAILS),
        ]);
    }
}
