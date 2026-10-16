<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

/**
 * Runs a write with its side effects off: no email of any kind leaves the site (WooCommerce order,
 * refund and account emails, WordPress notifications), stock is neither reduced nor restored, and an
 * order set to `refunded` doesn't get WooCommerce's automatic refund of its remaining total (the source's
 * own refunds are mirrored instead). Mirroring history must not act like it is happening now. When the operator keeps Shopify's HTML as-is,
 * WordPress's save-time HTML filter is off too (the runner has no user, so it would otherwise strip
 * embeds the merchant authored).
 */
final class Quiet
{
    private const FILTERS = ['woocommerce_can_reduce_order_stock', 'woocommerce_can_restore_order_stock'];
    private const MAIL_FILTER = 'pre_wp_mail';
    private const AUTO_REFUND_ACTION = 'woocommerce_order_status_refunded';
    private const AUTO_REFUND = 'wc_order_fully_refunded';

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
        $autoRefundPriority = has_action(self::AUTO_REFUND_ACTION, self::AUTO_REFUND);
        if (is_int($autoRefundPriority)) {
            remove_action(self::AUTO_REFUND_ACTION, self::AUTO_REFUND, $autoRefundPriority);
        }
        if ($keepRawHtml) {
            kses_remove_filters();
        }
        try {
            return $write();
        } finally {
            if ($keepRawHtml) {
                kses_init();
            }
            if (is_int($autoRefundPriority)) {
                add_action(self::AUTO_REFUND_ACTION, self::AUTO_REFUND, $autoRefundPriority);
            }
            remove_filter(self::MAIL_FILTER, '__return_true', PHP_INT_MAX);
            foreach (self::FILTERS as $filter) {
                remove_filter($filter, '__return_false', PHP_INT_MAX);
            }
        }
    }
}
