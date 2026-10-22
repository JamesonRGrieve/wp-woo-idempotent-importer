<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wave;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\ConnectsByOAuth;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\OAuth2\ClientAuth;
use IdempotentImporter\Source\OAuth2\OAuthApp;
use IdempotentImporter\Source\OAuth2\OAuthConnection;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\Wave\Mapping\CompanyMapper;
use IdempotentImporter\Source\Wave\Mapping\ContactMapper;
use IdempotentImporter\Source\Wave\Mapping\CustomerMapper;
use IdempotentImporter\Source\Wave\Mapping\InvoiceMapper;
use IdempotentImporter\Source\Wave\Mapping\ProductMapper;
use IdempotentImporter\Source\WebhookReceiver;

/**
 * Wave, through its public GraphQL API: products and services into WooCommerce products, customers into WooCommerce
 * customers and FluentCRM contacts (business customers as FluentCRM companies), and invoices with their payments into
 * WooCommerce orders. The operator creates an application in Wave's developer portal and connects the business
 * (OAuth 2 at api.waveapps.com; Wave opens its API only to businesses on a paid plan).
 */
final class WaveAdapter implements Adapter, ConnectsByOAuth
{
    public const ID = 'wave';
    public const CLIENT_ID = 'IIM_WAVE_CLIENT_ID';
    public const CLIENT_SECRET = 'IIM_WAVE_CLIENT_SECRET';
    /** The id of the business to mirror, when the connection opens several. */
    public const BUSINESS_ID = 'IIM_WAVE_BUSINESS_ID';
    public const TOKENS = 'IIM_WAVE_OAUTH_TOKENS';
    /** Test harness only: an access token issued elsewhere and the API host. Never settable from the admin. */
    public const ACCESS_TOKEN = 'IIM_WAVE_ACCESS_TOKEN';
    public const ENDPOINT_OVERRIDE = 'IIM_WAVE_ENDPOINT';
    public const AUTHORIZE_URL = 'https://api.waveapps.com/oauth2/authorize/';
    public const TOKEN_URL = 'https://api.waveapps.com/oauth2/token/';
    /** Read access to what the adapter reads (Wave's OAuth scopes: `<resource>:read`). */
    public const SCOPES = ['business:read', 'product:read', 'customer:read', 'invoice:read', 'sales_tax:read'];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Wave';
    }

    public function kinds(): array
    {
        return [Kind::Product, Kind::Customer, Kind::CrmCompany, Kind::Contact, Kind::Order];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new ProductMapper($settings->generateMissingSkus),
            new CustomerMapper(),
            new CompanyMapper(),
            new ContactMapper(),
            new InvoiceMapper(),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        $token = Credentials::constant(self::ACCESS_TOKEN);
        $authorizer = $token !== null
            ? BearerAuthorizer::token($token, $this->label())
            : BearerAuthorizer::oauth($this->connection()->client($transport));
        $business = trim(Credentials::get(self::BUSINESS_ID));

        return WaveReader::connect(Credentials::constant(self::ENDPOINT_OVERRIDE) ?? WaveReader::API_BASE, $authorizer, $transport, new SystemSleeper(), $business === '' ? null : $business);
    }

    public function webhooks(): ?WebhookReceiver
    {
        return null;
    }

    public function filterHint(): ?string
    {
        return null;
    }

    public function credentialFields(): array
    {
        return [
            self::CLIENT_ID => 'Wave application client ID',
            self::CLIENT_SECRET => 'Wave application client secret',
            self::BUSINESS_ID => 'Wave business ID (only when the connection opens several businesses)',
        ];
    }

    public function authorizationUrl(HttpTransport $transport, string $redirectUri, string $state, string $verifier): string
    {
        return $this->connection()->authorizationUrl($transport, $redirectUri, $state, $verifier);
    }

    public function callbackParameters(): array
    {
        return $this->connection()->callbackParameters();
    }

    public function complete(HttpTransport $transport, string $code, string $verifier, string $redirectUri, array $callback): void
    {
        $this->connection()->complete($transport, $code, $verifier, $redirectUri, $callback);
    }

    public function isConnected(): bool
    {
        return $this->connection()->isConnected();
    }

    private function connection(): OAuthConnection
    {
        return new OAuthConnection(self::TOKENS, fn(): OAuthApp => new OAuthApp(
            $this->label(),
            self::AUTHORIZE_URL,
            self::TOKEN_URL,
            Credentials::get(self::CLIENT_ID),
            Credentials::get(self::CLIENT_SECRET),
            self::SCOPES,
            ClientAuth::Form,
            refreshNamesRedirectUri: true,
        ));
    }
}
