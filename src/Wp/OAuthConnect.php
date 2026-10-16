<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Services;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\ConnectsByOAuth;
use IdempotentImporter\Source\Pkce;
use IdempotentImporter\Source\SourceError;

/**
 * "Connect to <source>" for adapters that authorise by OAuth: a nonce-checked start that keeps a random
 * state and PKCE verifier for the current user and sends them to the source, and the callback the source
 * returns to, which checks that state (constant time) before trading the code for tokens.
 */
final class OAuthConnect
{
    public const START_ACTION = 'iim_oauth_start';
    public const CALLBACK_ACTION = 'iim_oauth_callback';
    /** How long a started connection may take before its state expires. */
    public const PENDING_SECONDS = 600;

    private const PENDING_TRANSIENT = 'iim_oauth_pending_';
    private const STATE_LENGTH = 32;

    public function __construct(private readonly Services $services, private readonly AdminPage $page) {}

    public function register(): void
    {
        add_action('admin_post_' . self::START_ACTION, $this->start(...));
        add_action('admin_post_' . self::CALLBACK_ACTION, $this->callback(...));
    }

    /** The redirect URI to register with the source app (it must match exactly). */
    public static function redirectUri(): string
    {
        return admin_url('admin-post.php?action=' . self::CALLBACK_ACTION);
    }

    /** The connection status and Connect button, as a settings-table row. */
    public function row(Adapter&ConnectsByOAuth $adapter): void
    {
        $status = $adapter->isConnected() ? __('Connected', 'wp-woo-idempotent-importer') : __('Not connected', 'wp-woo-idempotent-importer');
        echo '<tr><th scope="row">' . esc_html(sprintf(__('%s shop', 'wp-woo-idempotent-importer'), $adapter->label())) . '</th><td><p>' . esc_html($status) . '</p>'
            . '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::START_ACTION), self::START_ACTION)) . '">'
            . esc_html(sprintf(__('Connect to %s', 'wp-woo-idempotent-importer'), $adapter->label())) . '</a></p>'
            . '<p class="description">' . esc_html__('Save the app credentials first. Register this callback URL with the app:', 'wp-woo-idempotent-importer')
            . ' <code>' . esc_html(self::redirectUri()) . '</code></p></td></tr>';
    }

    public function start(): void
    {
        $this->page->authorise(self::START_ACTION);
        $adapter = $this->adapter();
        $state = wp_generate_password(self::STATE_LENGTH, false);
        $verifier = Pkce::verifier();
        set_transient(self::PENDING_TRANSIENT . get_current_user_id(), ['state' => $state, 'verifier' => $verifier], self::PENDING_SECONDS);
        try {
            $url = $adapter->authorizationUrl($this->services->transport(), self::redirectUri(), $state, $verifier);
        } catch (SourceError $e) {
            $this->page->back($e->getMessage());
        }
        wp_redirect($url);
        exit; // WordPress's redirect contract.
    }

    public function callback(): void
    {
        if (!current_user_can(AdminPage::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do this.', 'wp-woo-idempotent-importer'), 403);
        }
        $key = self::PENDING_TRANSIENT . get_current_user_id();
        $pending = get_transient($key);
        delete_transient($key);
        $query = Input::query();
        $state = is_array($pending) && is_string($pending['state'] ?? null) ? $pending['state'] : '';
        $verifier = is_array($pending) && is_string($pending['verifier'] ?? null) ? $pending['verifier'] : '';
        if ($state === '' || !hash_equals($state, $query->text('state'))) {
            $this->page->back('The connection expired or did not start here; connect again.');
        }
        $code = $query->text('code');
        if ($code === '') {
            $this->page->back('The connection was not approved.');
        }
        try {
            $this->adapter()->complete($this->services->transport(), $code, $verifier, self::redirectUri());
        } catch (SourceError $e) {
            $this->page->back($e->getMessage());
        }
        $this->services->credentialHold()->release();
        $this->page->back('Connected.');
    }

    private function adapter(): ConnectsByOAuth
    {
        $adapter = $this->services->adapter();
        if (!$adapter instanceof ConnectsByOAuth) {
            $this->page->back("{$adapter->label()} doesn't connect with OAuth.");
        }

        return $adapter;
    }
}
