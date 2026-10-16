<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Removes the importer's own tables, options and scheduled actions. Mirrored WordPress content
 * (products, orders, customers, posts…) is the site's data now and is left in place.
 */

use IdempotentShopifyImporter\Config\Credentials;
use IdempotentShopifyImporter\Config\Settings;
use IdempotentShopifyImporter\Plugin;
use IdempotentShopifyImporter\Store\Tables;
use IdempotentShopifyImporter\Sync\Cursors;
use IdempotentShopifyImporter\Sync\RunLock;
use IdempotentShopifyImporter\Sync\RunState;
use IdempotentShopifyImporter\Wp\Scheduler;
use IdempotentShopifyImporter\Wp\Writer\DeliveryProfileWriter;

if (!defined('WP_UNINSTALL_PLUGIN')) {
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

(new Tables(Plugin::db()))->uninstall();
if (function_exists('as_unschedule_all_actions')) {
    Scheduler::unscheduleAll();
}
foreach ([
    Settings::OPTION, Credentials::OPTION, Cursors::OPTION, RunLock::OPTION, RunState::OPTION,
    Scheduler::LAST_FULL_OPTION, Scheduler::INTERVAL_OPTION, DeliveryProfileWriter::OWNED_METHODS_OPTION,
] as $option) {
    delete_option($option);
}
