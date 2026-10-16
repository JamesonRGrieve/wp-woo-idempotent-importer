<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp;

use IdempotentShopifyImporter\Config\Credentials;
use IdempotentShopifyImporter\Config\Settings;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Services;
use IdempotentShopifyImporter\Sync\Mode;

/**
 * WooCommerce → Shopify import: settings, credentials (write-only), run buttons and recent runs. Every
 * state change is a nonce-checked admin-post request by a user who can manage WooCommerce.
 */
final class AdminPage
{
    public const SLUG = 'shopify-import';
    public const CAPABILITY = 'manage_woocommerce';
    private const SAVE_ACTION = 'isi_save_settings';
    private const RUN_ACTION = 'isi_run';
    private const NOTICE_PARAM = 'isi_notice';
    private const BOOLEAN_SETTINGS = [
        'store_settings' => 'Mirror store settings (name, currency, address, weight unit, timezone)',
        'shipping_zones' => 'Mirror shipping zones from the default delivery profile',
        'redirects' => 'Redirect old Shopify URLs (301)',
        'localise_images' => 'Copy images embedded in descriptions and posts into the media library',
        'clean_html' => 'Clean Shopify HTML with WordPress\'s post filter',
        'preserve_order_numbers' => 'Show Shopify order numbers on mirrored orders',
        'generate_missing_skus' => 'Give variants without a SKU a stable one (needed by WooCommerce Square)',
    ];
    private const SYNCABLE_KINDS = [
        Kind::Shop, Kind::File, Kind::Collection, Kind::Product, Kind::Customer, Kind::Discount, Kind::Order,
        Kind::DraftOrder, Kind::Page, Kind::Blog, Kind::Article, Kind::Comment, Kind::Menu, Kind::UrlRedirect,
    ];

    public function __construct(private readonly Services $services, private readonly Scheduler $scheduler) {}

    public function register(): void
    {
        add_action('admin_menu', $this->menu(...));
        add_action('admin_post_' . self::SAVE_ACTION, $this->save(...));
        add_action('admin_post_' . self::RUN_ACTION, $this->runNow(...));
    }

    public function menu(): void
    {
        add_submenu_page('woocommerce', 'Shopify import', 'Shopify import', self::CAPABILITY, self::SLUG, $this->render(...));
    }

    public function render(): void
    {
        $settings = $this->services->settings();
        $notice = Input::query()->text(self::NOTICE_PARAM);
        echo '<div class="wrap"><h1>' . esc_html__('Shopify import', 'wp-idempotent-shopify-importer') . '</h1>';
        if ($notice !== '') {
            echo '<div class="notice notice-info" role="status"><p>' . esc_html($notice) . '</p></div>';
        }
        $this->runForm();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::SAVE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->textRow('store_domain', 'Store (name.myshopify.com)', $settings->storeDomain);
        $this->secretRow(Credentials::ACCESS_TOKEN, 'Admin API access token');
        $this->secretRow(Credentials::WEBHOOK_SECRET, 'Webhook signing secret (app client secret)');
        $this->numberRow('interval_minutes', 'Run every (minutes)', $settings->intervalMinutes, Settings::MIN_INTERVAL_MINUTES);
        $this->numberRow('full_reconcile_hours', 'Full reconcile every (hours)', $settings->fullReconcileHours, 1);
        $this->kindsRow($settings);
        foreach (self::BOOLEAN_SETTINGS as $key => $label) {
            $this->checkboxRow($key, $label, (bool) ($this->stored()[$key] ?? Settings::DEFAULTS[$key]));
        }
        $this->numberRow('default_author', 'Default article author (user ID)', $settings->defaultAuthor, 0);
        $this->textRow('notify_email', 'Email a summary after each run to', $settings->notifyEmail);
        echo '</tbody></table>';
        submit_button(__('Save settings', 'wp-idempotent-shopify-importer'));
        echo '</form>';
        $this->recentRuns();
        echo '<p>' . esc_html__('Webhook URL:', 'wp-idempotent-shopify-importer') . ' <code>' . esc_html(WebhookController::url()) . '</code></p></div>';
    }

    public function save(): void
    {
        $this->authorise(self::SAVE_ACTION);
        $input = Input::post();
        $stored = $this->stored();
        $stored['store_domain'] = $input->text('store_domain');
        $stored['interval_minutes'] = $input->int('interval_minutes', Settings::DEFAULTS['interval_minutes']);
        $stored['full_reconcile_hours'] = $input->int('full_reconcile_hours', Settings::DEFAULTS['full_reconcile_hours']);
        $stored['default_author'] = $input->int('default_author');
        $stored['notify_email'] = sanitize_email($input->text('notify_email'));
        $stored['kinds'] = array_values(array_filter($input->keys('kinds'), static fn(string $kind): bool => Kind::tryFrom($kind) !== null));
        $stored['filters'] = $input->textMap('filters');
        foreach (array_keys(self::BOOLEAN_SETTINGS) as $key) {
            $stored[$key] = $input->has($key);
        }
        update_option(Settings::OPTION, $stored, false);
        foreach ([Credentials::ACCESS_TOKEN, Credentials::WEBHOOK_SECRET] as $secret) {
            $value = trim($input->raw($secret));
            if ($value !== '' && !Credentials::isInjected($secret)) {
                Credentials::save($secret, $value);
            }
        }
        $this->back('Settings saved.');
    }

    public function runNow(): void
    {
        $this->authorise(self::RUN_ACTION);
        $input = Input::post();
        $mode = Mode::tryFrom($input->text('mode')) ?? Mode::Incremental;
        $started = $this->scheduler->startInBackground($mode, $input->has('dry_run'));
        $this->back($started ? 'Import started in the background.' : 'An import is already running, or the connection is not configured (see the log).');
    }

    private function runForm(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><p>';
        wp_nonce_field(self::RUN_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::RUN_ACTION) . '">';
        echo '<label for="isi-mode">' . esc_html__('Run', 'wp-idempotent-shopify-importer') . '</label> <select id="isi-mode" name="mode">';
        foreach (Mode::cases() as $mode) {
            echo '<option value="' . esc_attr($mode->value) . '">' . esc_html(ucfirst($mode->value)) . '</option>';
        }
        echo '</select> <label><input type="checkbox" name="dry_run"> ' . esc_html__('Dry run', 'wp-idempotent-shopify-importer') . '</label> ';
        submit_button(__('Start', 'wp-idempotent-shopify-importer'), 'secondary', 'submit', false);
        echo '</p></form>';
    }

    private function recentRuns(): void
    {
        echo '<h2>' . esc_html__('Recent runs', 'wp-idempotent-shopify-importer') . '</h2><table class="widefat striped"><thead><tr>';
        foreach (['Started (UTC)', 'Mode', 'Status', 'Writes', 'Summary'] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($this->services->runLog()->recent() as $run) {
            echo '<tr><td>' . esc_html($run->startedAt) . '</td><td>' . esc_html($run->label())
                . '</td><td>' . esc_html($run->status) . '</td><td>' . esc_html((string) $run->counts->writes())
                . '</td><td><pre>' . esc_html($run->message) . '</pre></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function kindsRow(Settings $settings): void
    {
        echo '<tr><th scope="row">' . esc_html__('Mirror', 'wp-idempotent-shopify-importer') . '</th><td><fieldset><legend class="screen-reader-text">'
            . esc_html__('Kinds to mirror, with optional Shopify search filters', 'wp-idempotent-shopify-importer') . '</legend>';
        foreach (self::SYNCABLE_KINDS as $kind) {
            $id = 'isi-kind-' . $kind->value;
            echo '<p><label for="' . esc_attr($id) . '"><input type="checkbox" id="' . esc_attr($id) . '" name="kinds[]" value="' . esc_attr($kind->value) . '"'
                . checked($settings->syncs($kind), true, false) . '> ' . esc_html($kind->value) . '</label> ';
            echo '<label class="screen-reader-text" for="' . esc_attr($id . '-filter') . '">' . esc_html("Filter for {$kind->value}") . '</label>';
            echo '<input type="text" class="regular-text" id="' . esc_attr($id . '-filter') . '" name="filters[' . esc_attr($kind->value) . ']" placeholder="'
                . esc_attr__('Shopify search, e.g. status:active', 'wp-idempotent-shopify-importer') . '" value="' . esc_attr($settings->filter($kind)) . '"></p>';
        }
        echo '</fieldset></td></tr>';
    }

    private function textRow(string $key, string $label, string $value): void
    {
        echo '<tr><th scope="row"><label for="isi-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input type="text" class="regular-text" id="isi-'
            . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '"></td></tr>';
    }

    private function numberRow(string $key, string $label, int $value, int $min): void
    {
        echo '<tr><th scope="row"><label for="isi-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input type="number" class="small-text" min="'
            . esc_attr((string) $min) . '" id="isi-' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr((string) $value) . '"></td></tr>';
    }

    private function checkboxRow(string $key, string $label, bool $checked): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label for="isi-' . esc_attr($key) . '"><input type="checkbox" id="isi-' . esc_attr($key)
            . '" name="' . esc_attr($key) . '"' . checked($checked, true, false) . '> ' . esc_html__('Enabled', 'wp-idempotent-shopify-importer') . '</label></td></tr>';
    }

    /** A write-only secret field: the saved value is never rendered back to the page. */
    private function secretRow(string $name, string $label): void
    {
        $state = Credentials::isInjected($name) ? 'Set by the server (wp-config or environment)' : 'Leave blank to keep the saved value';
        $disabled = Credentials::isInjected($name) ? ' disabled' : '';
        echo '<tr><th scope="row"><label for="isi-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input type="password" autocomplete="new-password" class="regular-text" id="isi-'
            . esc_attr($name) . '" name="' . esc_attr($name) . '"' . $disabled . ' aria-describedby="isi-' . esc_attr($name) . '-help"><p class="description" id="isi-'
            . esc_attr($name) . '-help">' . esc_html($state) . '</p></td></tr>';
    }

    /** @return array<array-key, mixed> */
    private function stored(): array
    {
        return Options::array(Settings::OPTION);
    }

    private function authorise(string $action): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do this.', 'wp-idempotent-shopify-importer'), 403);
        }
        check_admin_referer($action);
    }

    private function back(string $notice): void
    {
        wp_safe_redirect(add_query_arg([self::NOTICE_PARAM => rawurlencode($notice)], admin_url('admin.php?page=' . self::SLUG)));
        exit; // WordPress's redirect contract.
    }
}
