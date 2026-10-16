<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Plugin Name: Idempotent Shopify Importer
 * Description: Keeps WooCommerce a one-way, idempotent mirror of a Shopify store until cutover.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * WC requires at least: 9.6
 * Author: Jameson Grieve
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: wp-woo-idempotent-shopify-importer
 *
 * Portions adapted from "S2W - Import Shopify to WooCommerce" by VillaTheme (GPL-2.0-or-later); see NOTICE.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

IdempotentShopifyImporter\Plugin::boot(__FILE__);
