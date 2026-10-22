<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\FreshBooks;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\ConnectsByOAuth;
use IdempotentImporter\Source\FreshBooks\Mapping\ContactMapper;
use IdempotentImporter\Source\FreshBooks\Mapping\CreditNoteMapper;
use IdempotentImporter\Source\FreshBooks\Mapping\CustomerMapper;
use IdempotentImporter\Source\FreshBooks\Mapping\InvoiceMapper;
use IdempotentImporter\Source\FreshBooks\Mapping\OrganizationMapper;
use IdempotentImporter\Source\FreshBooks\Mapping\ProductMapper;
use IdempotentImporter\Source\FreshBooks\Mapping\StoreCreditMapper;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\OAuth2\ClientAuth;
use IdempotentImporter\Source\OAuth2\OAuthApp;
use IdempotentImporter\Source\OAuth2\OAuthConnection;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\WebhookReceiver;

/**
 * FreshBooks, through its API: items and services into WooCommerce products, clients into WooCommerce customers
 * and FluentCRM contacts (with the organizations they name as FluentCRM companies), invoices with their
 * payments into WooCommerce orders, credit notes applied to invoices into refunds on them, and what is left of a
 * client's credit notes into their store credit (Square Gift Cards). The operator creates an app in FreshBooks' developer portal and connects the
 * login (OAuth 2: JSON token requests that name the redirect URI, refresh tokens used once each).
 */
final class FreshBooksAdapter implements Adapter, ConnectsByOAuth
{
    public const ID = 'freshbooks';
    public const CLIENT_ID = 'IIM_FRESHBOOKS_CLIENT_ID';
    public const CLIENT_SECRET = 'IIM_FRESHBOOKS_CLIENT_SECRET';
    /** The account id of the business to mirror, when the login is a member of several. */
    public const ACCOUNT_ID = 'IIM_FRESHBOOKS_ACCOUNT_ID';
    public const TOKENS = 'IIM_FRESHBOOKS_OAUTH_TOKENS';
    /** Test harness only: an access token issued elsewhere and the API host. Never settable from the admin. */
    public const ACCESS_TOKEN = 'IIM_FRESHBOOKS_ACCESS_TOKEN';
    public const ENDPOINT_OVERRIDE = 'IIM_FRESHBOOKS_ENDPOINT';
    public const AUTHORIZE_URL = 'https://auth.freshbooks.com/oauth/authorize';
    public const TOKEN_URL = 'https://api.freshbooks.com/auth/oauth/token';
    /** Read access to the login, clients, invoices, payments and billable items (www.freshbooks.com/api/scopes). */
    public const SCOPES = ['user:profile:read', 'user:clients:read', 'user:invoices:read', 'user:payments:read', 'user:billable_items:read'];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'FreshBooks';
    }

    public function kinds(): array
    {
        return [Kind::Product, Kind::Customer, Kind::CrmCompany, Kind::Contact, Kind::Order, Kind::Return, Kind::GiftCard];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new ProductMapper($settings->generateMissingSkus),
            new CustomerMapper(),
            new OrganizationMapper(),
            new ContactMapper(),
            new InvoiceMapper(),
            new CreditNoteMapper(),
            new StoreCreditMapper($settings),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        $token = Credentials::constant(self::ACCESS_TOKEN);
        $authorizer = $token !== null
            ? BearerAuthorizer::token($token, $this->label())
            : BearerAuthorizer::oauth($this->connection()->client($transport));
        $account = trim(Credentials::get(self::ACCOUNT_ID));

        return FreshBooksReader::connect(Credentials::constant(self::ENDPOINT_OVERRIDE) ?? FreshBooksReader::API_BASE, $authorizer, $transport, new SystemSleeper(), $account === '' ? null : $account);
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
            self::CLIENT_ID => 'FreshBooks app client ID',
            self::CLIENT_SECRET => 'FreshBooks app client secret',
            self::ACCOUNT_ID => 'FreshBooks account ID (only when your login has several businesses)',
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
            ClientAuth::Json,
            refreshNamesRedirectUri: true,
        ));
    }
}
