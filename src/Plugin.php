<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter;

use IdempotentImporter\Wp\AdminPage;
use IdempotentImporter\Wp\Cli;
use IdempotentImporter\Wp\DynamicCollections;
use IdempotentImporter\Wp\Scheduler;
use IdempotentImporter\Wp\Storefront;
use IdempotentImporter\Wp\WebhookController;
use WP_CLI;
use wpdb;

/** Wires the plugin into WordPress once WooCommerce is loaded. */
final class Plugin
{
    public const VERSION = '0.0.1';
    public const CLI_COMMAND = 'idempotent-import';

    public static function boot(string $pluginFile): void
    {
        register_activation_hook($pluginFile, self::activate(...));
        register_deactivation_hook($pluginFile, Scheduler::unscheduleAll(...));
        add_action('before_woocommerce_init', static function () use ($pluginFile): void {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $pluginFile, true);
            }
        });
        add_action('plugins_loaded', self::start(...));
    }

    public static function activate(): void
    {
        (new Services(self::db()))->tables()->install();
    }

    /** WordPress's database connection, typed. */
    public static function db(): wpdb
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            throw new \LogicException('WordPress has not set up its database connection.');
        }

        return $wpdb;
    }

    public static function start(): void
    {
        if (!class_exists(\WooCommerce::class)) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html__('Store import needs WooCommerce to be active.', 'wp-woo-idempotent-importer') . '</p></div>';
            });

            return;
        }
        $services = new Services(self::db());
        $services->tables()->install();
        $scheduler = new Scheduler($services);
        $scheduler->register();
        (new Storefront($services->settings(), $services->redirects(), $services->links()))->register();
        (new DynamicCollections())->register();
        if ($services->adapter()->supportsWebhooks()) {
            $webhooks = new WebhookController($scheduler);
            add_action('rest_api_init', $webhooks->register(...));
        }
        if (is_admin()) {
            (new AdminPage($services, $scheduler))->register();
        }
        if (defined('WP_CLI') && constant('WP_CLI') === true) {
            WP_CLI::add_command(self::CLI_COMMAND, new Cli($services, $scheduler));
        }
    }
}
