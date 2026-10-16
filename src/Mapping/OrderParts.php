<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Mapping;

use IdempotentShopifyImporter\Shopify\ShopifyNode;

/** The parts orders and draft orders share, translated once for both. */
final class OrderParts
{
    /**
     * Everything common to an order and a draft order.
     *
     * @param list<ShopifyNode> $shippingLines
     *
     * @return array<string, mixed>
     */
    public static function common(ShopifyNode $node, array $shippingLines): array
    {
        return [
            'name' => $node->string('name'),
            'created_at' => $node->string('createdAt'),
            'currency' => $node->string('currencyCode'),
            'prices_include_tax' => $node->bool('taxesIncluded'),
            'customer_gid' => Fields::childId($node, 'customer'),
            'email' => $node->optionalString('email'),
            'phone' => $node->optionalString('phone'),
            'billing' => Fields::address($node->child('billingAddress')),
            'shipping' => Fields::address($node->child('shippingAddress')),
            'tags' => $node->strings('tags'),
            'custom_attributes' => Fields::attributes($node),
            'coupons' => $node->strings('discountCodes'),
            'line_items' => array_map(self::lineItem(...), $node->list('lineItems')),
            'shipping_lines' => array_map(self::shippingLine(...), $shippingLines),
            'tax_lines' => Fields::taxLines($node->list('taxLines')),
            'subtotal' => Fields::moneyOrZero($node, 'subtotalPriceSet'),
            'discount_total' => Fields::moneyOrZero($node, 'totalDiscountsSet'),
            'shipping_total' => Fields::moneyOrZero($node, 'totalShippingPriceSet'),
            'tax_total' => Fields::moneyOrZero($node, 'totalTaxSet'),
            'total' => Fields::moneyOrZero($node, 'totalPriceSet'),
            'meta' => Fields::metafields($node),
        ];
    }

    /** @return array<string, mixed> */
    public static function lineItem(ShopifyNode $line): array
    {
        return [
            'gid' => $line->id(),
            'name' => $line->string('name'),
            'gift_card' => $line->bool('isGiftCard'),
            'product_gid' => Fields::childId($line, 'product'),
            'variant_gid' => Fields::childId($line, 'variant'),
            'sku' => $line->optionalString('sku'),
            'quantity' => $line->int('quantity'),
            'taxable' => $line->bool('taxable'),
            'subtotal' => Fields::moneyOrZero($line, 'originalTotalSet'),
            'total' => Fields::moneyOrZero($line, 'discountedTotalSet'),
            'taxes' => Fields::taxLines($line->list('taxLines')),
            'attributes' => Fields::attributes($line),
        ];
    }

    /** @return array<string, mixed> */
    public static function shippingLine(ShopifyNode $line): array
    {
        return [
            'title' => $line->string('title'),
            'code' => $line->optionalString('code'),
            'source' => $line->optionalString('source'),
            'total' => Fields::moneyOrZero($line, 'discountedPriceSet'),
            'taxes' => Fields::taxLines($line->list('taxLines')),
        ];
    }
}
