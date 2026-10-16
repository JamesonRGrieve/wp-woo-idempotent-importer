<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * End-to-end environment only: lets WordPress download media from the fake Shopify container, whose
 * address is private (WordPress refuses private hosts by default), and registers the suite's checks as
 * `wp isi-e2e <scenario>`.
 */

const ISI_E2E_FAKE_HOST = 'shopify-fake';
const ISI_E2E_CHECKS = WP_PLUGIN_DIR . '/wp-woo-idempotent-shopify-importer/tests/e2e/assert.php';

const ISI_E2E_MAIL_COUNT = 'isi_e2e_mail_sent';

add_filter('http_request_host_is_external', static fn(bool $external, string $host): bool => $external || $host === ISI_E2E_FAKE_HOST, 10, 2);

// Counts every email that gets as far as the mailer (the importer's quiet writes must never get there).
add_action('phpmailer_init', static function (): void {
    $sent = get_option(ISI_E2E_MAIL_COUNT, 0);
    update_option(ISI_E2E_MAIL_COUNT, (is_numeric($sent) ? (int) $sent : 0) + 1, false);
});

if (defined('WP_CLI') && constant('WP_CLI') === true) {
    add_action('plugins_loaded', static function (): void {
        require ISI_E2E_CHECKS;
    }, 20);
}
