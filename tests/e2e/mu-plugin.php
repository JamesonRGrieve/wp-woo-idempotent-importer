<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * End-to-end environment only: lets WordPress download media from the fake store container (reachable as
 * <source>-fake), whose address is private (WordPress refuses private hosts by default), and registers the
 * suite's checks as `wp iim-e2e <scenario>`.
 */

const IIM_E2E_FAKE_HOST_SUFFIX = '-fake';
const IIM_E2E_CHECKS = WP_PLUGIN_DIR . '/wp-woo-idempotent-importer/tests/e2e/assert.php';

const IIM_E2E_MAIL_COUNT = 'iim_e2e_mail_sent';

add_filter('http_request_host_is_external', static fn(bool $external, string $host): bool => $external || str_ends_with($host, IIM_E2E_FAKE_HOST_SUFFIX), 10, 2);

// Counts every email that gets as far as the mailer (the importer's quiet writes must never get there).
add_action('phpmailer_init', static function (): void {
    $sent = get_option(IIM_E2E_MAIL_COUNT, 0);
    update_option(IIM_E2E_MAIL_COUNT, (is_numeric($sent) ? (int) $sent : 0) + 1, false);
});

// Counts every FluentCRM contact, list and tag event (they start automations and email sequences, which a mirror
// must never do).
const IIM_E2E_FLUENT_EVENTS = 'iim_e2e_fluent_events';
foreach (['fluent_crm/contact_created', 'fluent_crm/contact_updated', 'fluent_crm/contact_added_to_lists', 'fluent_crm/contact_added_to_tags', 'fluent_crm/contact_removed_from_lists', 'fluent_crm/contact_custom_data_updated', 'fluent_crm/company_created'] as $iimE2eEvent) {
    add_action($iimE2eEvent, static function (): void {
        $count = get_option(IIM_E2E_FLUENT_EVENTS, 0);
        update_option(IIM_E2E_FLUENT_EVENTS, (is_numeric($count) ? (int) $count : 0) + 1, false);
    });
}

if (defined('WP_CLI') && constant('WP_CLI') === true) {
    add_action('plugins_loaded', static function (): void {
        require IIM_E2E_CHECKS;
    }, 20);
}
