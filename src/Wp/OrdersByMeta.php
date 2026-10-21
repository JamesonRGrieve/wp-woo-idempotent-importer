<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Abstract_Order;

/**
 * Orders (or refunds) carrying one meta value, on either order store. HPOS takes a `meta_query`; the
 * posts store takes `meta_key`/`meta_value` (it ignores a `meta_query`, and would return every order).
 * Each result is checked for the value itself, so a query argument a store ignores never matches wrongly.
 */
final class OrdersByMeta
{
    public const ORDER = 'shop_order';
    public const REFUND = 'shop_order_refund';

    /** @return list<WC_Abstract_Order> */
    public static function find(string $type, string $key, string $value, int $limit = -1): array
    {
        $args = [
            'type' => $type,
            'limit' => $limit,
            'status' => array_keys(wc_get_order_statuses()),
        ];
        $args += OrderUtil::custom_orders_table_usage_is_enabled()
            ? ['meta_query' => [['key' => $key, 'value' => $value]]]
            : ['meta_key' => $key, 'meta_value' => $value];
        $found = [];
        foreach ((array) wc_get_orders($args) as $order) {
            if ($order instanceof WC_Abstract_Order && $order->get_meta($key) === $value) {
                $found[] = $order;
            }
        }

        return $found;
    }
}
