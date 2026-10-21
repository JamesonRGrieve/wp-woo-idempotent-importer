<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\WebhookEvent;

/**
 * Which object a webhook is about. Every topic resolves to one object to re-read (or retire); the
 * re-read goes through the same sync as a scheduled run, so a webhook can never write differently.
 */
final class WebhookTopics
{
    /** Shopify topic => [kind, GID type, payload field holding the id, whether it is a deletion]. */
    private const TOPICS = [
        'PRODUCTS_CREATE' => [Kind::Product, 'Product', 'id', false],
        'PRODUCTS_UPDATE' => [Kind::Product, 'Product', 'id', false],
        'PRODUCTS_DELETE' => [Kind::Product, 'Product', 'id', true],
        'COLLECTIONS_CREATE' => [Kind::Collection, 'Collection', 'id', false],
        'COLLECTIONS_UPDATE' => [Kind::Collection, 'Collection', 'id', false],
        'COLLECTIONS_DELETE' => [Kind::Collection, 'Collection', 'id', true],
        'CUSTOMERS_CREATE' => [Kind::Customer, 'Customer', 'id', false],
        'CUSTOMERS_UPDATE' => [Kind::Customer, 'Customer', 'id', false],
        'CUSTOMERS_ENABLE' => [Kind::Customer, 'Customer', 'id', false],
        'CUSTOMERS_DISABLE' => [Kind::Customer, 'Customer', 'id', false],
        'CUSTOMERS_DELETE' => [Kind::Customer, 'Customer', 'id', true],
        'ORDERS_CREATE' => [Kind::Order, 'Order', 'id', false],
        'ORDERS_UPDATED' => [Kind::Order, 'Order', 'id', false],
        'ORDERS_EDITED' => [Kind::Order, 'Order', 'order_id', false],
        'ORDERS_PAID' => [Kind::Order, 'Order', 'id', false],
        'ORDERS_CANCELLED' => [Kind::Order, 'Order', 'id', false],
        'ORDERS_FULFILLED' => [Kind::Order, 'Order', 'id', false],
        'ORDERS_PARTIALLY_FULFILLED' => [Kind::Order, 'Order', 'id', false],
        'ORDERS_DELETE' => [Kind::Order, 'Order', 'id', true],
        'REFUNDS_CREATE' => [Kind::Order, 'Order', 'order_id', false],
        'FULFILLMENTS_CREATE' => [Kind::Order, 'Order', 'order_id', false],
        'FULFILLMENTS_UPDATE' => [Kind::Order, 'Order', 'order_id', false],
        'DRAFT_ORDERS_CREATE' => [Kind::DraftOrder, 'DraftOrder', 'id', false],
        'DRAFT_ORDERS_UPDATE' => [Kind::DraftOrder, 'DraftOrder', 'id', false],
        'DRAFT_ORDERS_DELETE' => [Kind::DraftOrder, 'DraftOrder', 'id', true],
        'DISCOUNTS_CREATE' => [Kind::Discount, null, 'admin_graphql_api_id', false],
        'DISCOUNTS_UPDATE' => [Kind::Discount, null, 'admin_graphql_api_id', false],
        'DISCOUNTS_REDEEMCODE_ADDED' => [Kind::Discount, null, 'admin_graphql_api_id', false],
        'DISCOUNTS_REDEEMCODE_REMOVED' => [Kind::Discount, null, 'admin_graphql_api_id', false],
        'DISCOUNTS_DELETE' => [Kind::Discount, null, 'admin_graphql_api_id', true],
        'INVENTORY_LEVELS_UPDATE' => [Kind::Product, 'InventoryItem', 'inventory_item_id', false],
        'SHOP_UPDATE' => [Kind::Shop, 'Shop', 'id', false],
    ];

    /** @return list<string> every topic the importer subscribes to */
    public static function topics(): array
    {
        return array_keys(self::TOPICS);
    }

    /**
     * The object a webhook concerns. An INVENTORY_LEVELS_UPDATE GID names an InventoryItem; the caller
     * resolves it to its product before syncing.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function resolve(string $headerTopic, array $payload): ?WebhookEvent
    {
        $topic = strtoupper(str_replace('/', '_', $headerTopic));
        if (!isset(self::TOPICS[$topic])) {
            return null;
        }
        [$kind, $gidType, $field, $deletion] = self::TOPICS[$topic];
        $value = $payload[$field] ?? null;
        if (is_int($value) || (is_string($value) && $value !== '')) {
            $gid = $gidType === null ? (string) $value : Gid::of($gidType, (string) $value);

            return new WebhookEvent($kind, $gid, $deletion);
        }

        return null;
    }
}
