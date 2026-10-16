<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

/**
 * Runs a write with its side effects off: no email of any kind leaves the site (WooCommerce order,
 * refund and account emails, WordPress notifications), and stock is neither reduced nor restored.
 * Mirroring history must not act like it is happening now. When the operator keeps Shopify's HTML as-is,
 * WordPress's save-time HTML filter is off too (the runner has no user, so it would otherwise strip
 * embeds the merchant authored).
 */
final class Quiet
{
    private const FILTERS = ['woocommerce_can_reduce_order_stock', 'woocommerce_can_restore_order_stock'];
    private const MAIL_FILTER = 'pre_wp_mail';

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public static function run(bool $keepRawHtml, callable $write): mixed
    {
        foreach (self::FILTERS as $filter) {
            add_filter($filter, '__return_false', PHP_INT_MAX);
        }
        add_filter(self::MAIL_FILTER, '__return_true', PHP_INT_MAX);
        if ($keepRawHtml) {
            kses_remove_filters();
        }
        try {
            return $write();
        } finally {
            if ($keepRawHtml) {
                kses_init();
            }
            remove_filter(self::MAIL_FILTER, '__return_true', PHP_INT_MAX);
            foreach (self::FILTERS as $filter) {
                remove_filter($filter, '__return_false', PHP_INT_MAX);
            }
        }
    }
}
