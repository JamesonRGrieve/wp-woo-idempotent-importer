<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\LinkStore;
use IdempotentImporter\Store\RedirectStore;
use IdempotentImporter\Wp\Writer\OrderWriter;
use WC_Order;

/**
 * Storefront behaviour: 301s from old Shopify URLs (only when WordPress itself would 404, so real
 * WordPress pages always win), and Shopify order numbers shown on mirrored orders.
 */
final class Storefront
{
    private const MOVED_PERMANENTLY = 301;
    private const MAX_REDIRECT_HOPS = 3;
    private const POST_KINDS = [Kind::Product, Kind::Page, Kind::Article, Kind::Policy];
    private const TERM_KINDS = [Kind::Collection, Kind::Blog];

    public function __construct(
        private readonly Settings $settings,
        private readonly RedirectStore $redirects,
        private readonly LinkStore $links,
    ) {}

    public function register(): void
    {
        if ($this->settings->redirects) {
            add_action('template_redirect', $this->redirectLegacyUrl(...), 1);
        }
        if ($this->settings->preserveOrderNumbers) {
            add_filter('woocommerce_order_number', $this->orderNumber(...), 10, 2);
        }
        add_filter('woocommerce_shop_order_search_fields', $this->searchFields(...));
        add_filter('woocommerce_order_table_search_query_meta_keys', $this->searchFields(...));
    }

    public function redirectLegacyUrl(): void
    {
        if (!is_404()) {
            return;
        }
        $target = $this->target(LegacyPaths::normalise(Input::server()->raw('REQUEST_URI')));
        if ($target !== null && wp_safe_redirect($target, self::MOVED_PERMANENTLY)) {
            exit; // WordPress's redirect contract: nothing may render after the Location header.
        }
    }

    /** Where an old Shopify path now lives, following Shopify's own redirects a few hops. */
    public function target(string $path, int $hops = 0): ?string
    {
        $row = $this->redirects->find($path);
        if ($row === null) {
            return $this->fixedTarget($path);
        }
        if ($row['target_url'] !== null) {
            $next = $row['target_url'];
            if (str_starts_with($next, '/') && $hops < self::MAX_REDIRECT_HOPS) {
                return $this->target(LegacyPaths::normalise($next), $hops + 1) ?? home_url($next);
            }

            return $next;
        }
        $objectId = $this->links->targetId($row['kind'], $row['gid']);
        if ($objectId === null) {
            return null;
        }
        if (in_array($row['kind'], self::TERM_KINDS, true)) {
            $link = get_term_link($objectId);

            return is_string($link) ? $link : null;
        }
        $link = in_array($row['kind'], self::POST_KINDS, true) ? get_permalink($objectId) : false;

        return is_string($link) ? $link : null;
    }

    public function orderNumber(string $number, WC_Order $order): string
    {
        $shopifyName = $order->get_meta(OrderWriter::ORDER_NUMBER_META);

        return is_string($shopifyName) && $shopifyName !== '' ? $shopifyName : $number;
    }

    /**
     * @param list<string> $fields
     *
     * @return list<string>
     */
    public function searchFields(array $fields): array
    {
        return [...$fields, OrderWriter::ORDER_NUMBER_META];
    }

    /** Shopify's fixed storefront paths. */
    private function fixedTarget(string $path): ?string
    {
        return match ($path) {
            '/collections', '/collections/all', '/products' => self::url(wc_get_page_permalink('shop')),
            '/cart' => self::url(wc_get_cart_url()),
            '/checkout' => self::url(wc_get_checkout_url()),
            '/account', '/account/login', '/account/register' => self::url(wc_get_page_permalink('myaccount')),
            '/blogs' => self::url(get_post_type_archive_link('post') ?: home_url('/')),
            default => null,
        };
    }

    private static function url(string|false $url): ?string
    {
        return is_string($url) && $url !== '' ? $url : null;
    }
}
