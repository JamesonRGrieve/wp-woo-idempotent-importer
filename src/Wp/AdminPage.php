<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Services;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\Adapters;
use IdempotentImporter\Source\ConnectsByOAuth;
use IdempotentImporter\Source\Shopify\ShopifyAdapter;
use IdempotentImporter\Sync\Mode;

/**
 * WooCommerce → Store import: settings, credentials (write-only), run buttons and recent runs. Every
 * state change is a nonce-checked admin-post request by a user who can manage WooCommerce.
 */
final class AdminPage
{
    public const SLUG = 'idempotent-import';
    public const CAPABILITY = 'manage_woocommerce';
    private const SAVE_ACTION = 'iim_save_settings';
    private const RUN_ACTION = 'iim_run';
    private const NOTICE_PARAM = 'iim_notice';
    private const BOOLEAN_SETTINGS = [
        'store_settings' => 'Mirror store settings (name, currency, address, weight unit, timezone)',
        'shipping_zones' => 'Mirror shipping zones from the default delivery profile',
        'redirects' => 'Redirect old store URLs (301)',
        'localise_images' => 'Copy images embedded in descriptions and posts into the media library',
        'clean_html' => 'Clean imported HTML with WordPress\'s post filter',
        'preserve_order_numbers' => 'Show the source store\'s order numbers on mirrored orders',
        'generate_missing_skus' => 'Give variants without a SKU a stable one (needed by WooCommerce Square)',
        'gift_card_load_increases' => 'Load gift card balance increases into Square (Square charges a load fee on them)',
        'gift_card_new_numbers' => 'Give gift cards whose source code Square can\'t use (or the source hides) a new number, shown in the customer\'s account',
    ];
    /** Kinds switched by their own setting (or with orders) rather than the kinds list. */
    private const SEPARATELY_SWITCHED_KINDS = [Kind::DeliveryProfile, Kind::Return];

    private readonly OAuthConnect $oauth;

    public function __construct(private readonly Services $services, private readonly Scheduler $scheduler)
    {
        $this->oauth = new OAuthConnect($services, $this);
    }

    public function register(): void
    {
        $this->oauth->register();
        add_action('admin_menu', $this->menu(...));
        add_action('admin_post_' . self::SAVE_ACTION, $this->save(...));
        add_action('admin_post_' . self::RUN_ACTION, $this->runNow(...));
    }

    public function menu(): void
    {
        add_submenu_page('woocommerce', 'Store import', 'Store import', self::CAPABILITY, self::SLUG, $this->render(...));
    }

    public function render(): void
    {
        $settings = $this->services->settings();
        $notice = Input::query()->text(self::NOTICE_PARAM);
        echo '<div class="wrap"><h1>' . esc_html__('Store import', 'wp-woo-idempotent-importer') . '</h1>';
        if ($notice !== '') {
            echo '<div class="notice notice-info" role="status"><p>' . esc_html($notice) . '</p></div>';
        }
        $hold = $this->services->credentialHold()->reason($settings->source);
        if ($hold !== null) {
            echo '<div class="notice notice-error" role="alert"><p>' . esc_html(
                "Scheduled runs are paused: the source rejected the credentials — {$hold}. Fix them and save the settings, or start a run below, to try again.",
            ) . '</p></div>';
        }
        $this->runForm();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::SAVE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';
        $adapter = $this->services->adapter();
        $this->sourceRow($adapter);
        if ($adapter->id() === ShopifyAdapter::ID) {
            $this->textRow('store_domain', 'Store (name.myshopify.com)', $settings->storeDomain);
        }
        foreach ($adapter->credentialFields() as $name => $label) {
            $this->secretRow($name, $label);
        }
        if ($adapter instanceof ConnectsByOAuth) {
            $this->oauth->row($adapter);
        }
        $this->numberRow('interval_minutes', 'Run every (minutes)', $settings->intervalMinutes, Settings::MIN_INTERVAL_MINUTES);
        $this->numberRow('full_reconcile_hours', 'Full reconcile every (hours)', $settings->fullReconcileHours, 1);
        $this->kindsRow($settings, $adapter);
        foreach (self::BOOLEAN_SETTINGS as $key => $label) {
            $this->checkboxRow($key, $label, (bool) ($this->stored()[$key] ?? Settings::DEFAULTS[$key]));
        }
        $this->numberRow('default_author', 'Default article author (user ID)', $settings->defaultAuthor, 0);
        $this->textRow('notify_email', 'Email a summary after each run to', $settings->notifyEmail);
        echo '</tbody></table>';
        submit_button(__('Save settings', 'wp-woo-idempotent-importer'));
        echo '</form>';
        $this->recentRuns();
        if ($adapter->webhooks() !== null) {
            echo '<p>' . esc_html__('Webhook URL:', 'wp-woo-idempotent-importer') . ' <code>' . esc_html(WebhookController::url()) . '</code></p>';
        }
        echo '</div>';
    }

    public function save(): void
    {
        $this->authorise(self::SAVE_ACTION);
        $input = Input::post();
        $stored = $this->stored();
        $source = $input->text('source');
        $stored['source'] = Adapters::exists($source) ? $source : $this->services->settings()->source;
        if ($input->has('store_domain')) {
            $stored['store_domain'] = $input->text('store_domain');
        }
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
        foreach (array_keys($this->services->adapter()->credentialFields()) as $secret) {
            $value = trim($input->raw($secret));
            if ($value !== '' && !Credentials::isInjected($secret)) {
                Credentials::save($secret, $value);
            }
        }
        $this->services->credentialHold()->release();
        $this->back('Settings saved.');
    }

    public function runNow(): void
    {
        $this->authorise(self::RUN_ACTION);
        $input = Input::post();
        $mode = Mode::tryFrom($input->text('mode')) ?? Mode::Incremental;
        $this->services->credentialHold()->release();
        $started = $this->scheduler->startInBackground($mode, $input->has('dry_run'));
        $this->back($started ? 'Import started in the background.' : 'An import is already running, or the connection is not configured (see the log).');
    }

    private function runForm(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><p>';
        wp_nonce_field(self::RUN_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::RUN_ACTION) . '">';
        echo '<label for="iim-mode">' . esc_html__('Run', 'wp-woo-idempotent-importer') . '</label> <select id="iim-mode" name="mode">';
        foreach (Mode::cases() as $mode) {
            echo '<option value="' . esc_attr($mode->value) . '">' . esc_html(ucfirst($mode->value)) . '</option>';
        }
        echo '</select> <label><input type="checkbox" name="dry_run"> ' . esc_html__('Dry run', 'wp-woo-idempotent-importer') . '</label> ';
        submit_button(__('Start', 'wp-woo-idempotent-importer'), 'secondary', 'submit', false);
        echo '</p></form>';
    }

    private function recentRuns(): void
    {
        echo '<h2>' . esc_html__('Recent runs', 'wp-woo-idempotent-importer') . '</h2><table class="widefat striped"><thead><tr>';
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

    /** The source platform. Saving applies it. */
    private function sourceRow(Adapter $active): void
    {
        echo '<tr><th scope="row"><label for="iim-source">' . esc_html__('Import from', 'wp-woo-idempotent-importer') . '</label></th><td><select id="iim-source" name="source">';
        foreach (Adapters::all() as $adapter) {
            echo '<option value="' . esc_attr($adapter->id()) . '"' . selected($adapter->id(), $active->id(), false)
                . '>' . esc_html($adapter->label()) . '</option>';
        }
        echo '</select></td></tr>';
    }

    private function kindsRow(Settings $settings, Adapter $adapter): void
    {
        $hint = $adapter->filterHint();
        echo '<tr><th scope="row">' . esc_html__('Mirror', 'wp-woo-idempotent-importer') . '</th><td><fieldset><legend class="screen-reader-text">'
            . esc_html__('Kinds to mirror', 'wp-woo-idempotent-importer') . '</legend>';
        foreach ($adapter->kinds() as $kind) {
            if (in_array($kind, self::SEPARATELY_SWITCHED_KINDS, true)) {
                continue;
            }
            $id = 'iim-kind-' . $kind->value;
            echo '<p><label for="' . esc_attr($id) . '"><input type="checkbox" id="' . esc_attr($id) . '" name="kinds[]" value="' . esc_attr($kind->value) . '"'
                . checked($settings->syncs($kind), true, false) . '> ' . esc_html($kind->value) . '</label> ';
            if ($hint !== null) {
                echo '<label class="screen-reader-text" for="' . esc_attr($id . '-filter') . '">' . esc_html("Filter for {$kind->value}") . '</label>';
                echo '<input type="text" class="regular-text" id="' . esc_attr($id . '-filter') . '" name="filters[' . esc_attr($kind->value) . ']" placeholder="'
                    . esc_attr($hint) . '" value="' . esc_attr($settings->filter($kind)) . '">';
            }
            echo '</p>';
        }
        echo '</fieldset></td></tr>';
    }

    private function textRow(string $key, string $label, string $value): void
    {
        echo '<tr><th scope="row"><label for="iim-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input type="text" class="regular-text" id="iim-'
            . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '"></td></tr>';
    }

    private function numberRow(string $key, string $label, int $value, int $min): void
    {
        echo '<tr><th scope="row"><label for="iim-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input type="number" class="small-text" min="'
            . esc_attr((string) $min) . '" id="iim-' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr((string) $value) . '"></td></tr>';
    }

    private function checkboxRow(string $key, string $label, bool $checked): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label for="iim-' . esc_attr($key) . '"><input type="checkbox" id="iim-' . esc_attr($key)
            . '" name="' . esc_attr($key) . '"' . checked($checked, true, false) . '> ' . esc_html__('Enabled', 'wp-woo-idempotent-importer') . '</label></td></tr>';
    }

    /** A write-only secret field: the saved value is never rendered back to the page. */
    private function secretRow(string $name, string $label): void
    {
        $state = Credentials::isInjected($name) ? 'Set by the server (wp-config or environment)' : 'Leave blank to keep the saved value';
        $disabled = Credentials::isInjected($name) ? ' disabled' : '';
        echo '<tr><th scope="row"><label for="iim-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input type="password" autocomplete="new-password" class="regular-text" id="iim-'
            . esc_attr($name) . '" name="' . esc_attr($name) . '"' . $disabled . ' aria-describedby="iim-' . esc_attr($name) . '-help"><p class="description" id="iim-'
            . esc_attr($name) . '-help">' . esc_html($state) . '</p></td></tr>';
    }

    /** @return array<array-key, mixed> */
    private function stored(): array
    {
        return Options::array(Settings::OPTION);
    }

    public function authorise(string $action): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do this.', 'wp-woo-idempotent-importer'), 403);
        }
        check_admin_referer($action);
    }

    /** Back to the settings page with a notice. */
    public function back(string $notice): never
    {
        wp_safe_redirect(add_query_arg([self::NOTICE_PARAM => rawurlencode($notice)], admin_url('admin.php?page=' . self::SLUG)));
        exit; // WordPress's redirect contract.
    }
}
