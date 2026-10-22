<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\QuickBooksOnline;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\ConnectsByOAuth;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\OAuth2\ClientAuth;
use IdempotentImporter\Source\OAuth2\CredentialTokens;
use IdempotentImporter\Source\OAuth2\OAuthApp;
use IdempotentImporter\Source\OAuth2\OAuthConnection;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\CategoryMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\CompanyMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\ContactMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\CreditMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\CustomerMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\ProductMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\SaleMapper;
use IdempotentImporter\Source\QuickBooksOnline\Mapping\StoreCreditMapper;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\WebhookReceiver;

/**
 * QuickBooks Online, through Intuit's Accounting API: item categories and items into WooCommerce, customers into
 * WooCommerce customers and FluentCRM contacts (with their companies), and invoices (with the payments applied to
 * them) and sales receipts into WooCommerce orders, credit memos applied to invoices and refund receipts into refunds
 * on them, and what is left of a customer's credit memos into their store credit (Square Gift Cards). The operator creates an app on developer.intuit.com and connects
 * the company (OAuth 2: HTTP Basic client authentication, the `com.intuit.quickbooks.accounting` scope, which is the
 * narrowest that reads the books; the redirect names the company's realm id, kept with the tokens).
 */
final class QuickBooksOnlineAdapter implements Adapter, ConnectsByOAuth
{
    public const ID = 'quickbooks_online';
    public const CLIENT_ID = 'IIM_QUICKBOOKS_ONLINE_CLIENT_ID';
    public const CLIENT_SECRET = 'IIM_QUICKBOOKS_ONLINE_CLIENT_SECRET';
    /** The API host: blank for production, the sandbox host for a sandbox company. */
    public const API_HOST = 'IIM_QUICKBOOKS_ONLINE_API_HOST';
    public const TOKENS = 'IIM_QUICKBOOKS_ONLINE_OAUTH_TOKENS';
    /** Test harness only: an access token and realm issued elsewhere, and the API host. Never settable from the admin. */
    public const ACCESS_TOKEN = 'IIM_QUICKBOOKS_ONLINE_ACCESS_TOKEN';
    public const REALM_ID = 'IIM_QUICKBOOKS_ONLINE_REALM_ID';
    public const ENDPOINT_OVERRIDE = 'IIM_QUICKBOOKS_ONLINE_ENDPOINT';
    public const AUTHORIZE_URL = 'https://appcenter.intuit.com/connect/oauth2';
    public const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    public const SCOPES = ['com.intuit.quickbooks.accounting'];
    /** The redirect parameter naming the connected company. */
    public const REALM = 'realmId';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'QuickBooks Online';
    }

    public function kinds(): array
    {
        return [Kind::Collection, Kind::Product, Kind::Customer, Kind::CrmCompany, Kind::Contact, Kind::Order, Kind::Return, Kind::GiftCard];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new CategoryMapper(),
            new ProductMapper($settings->generateMissingSkus),
            new CustomerMapper(),
            new CompanyMapper(),
            new ContactMapper(),
            new SaleMapper(),
            new CreditMapper(),
            new StoreCreditMapper($settings),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        $token = Credentials::constant(self::ACCESS_TOKEN);
        if ($token !== null) {
            return QuickBooksOnlineReader::connect(
                Credentials::constant(self::ENDPOINT_OVERRIDE) ?? QuickBooksOnlineReader::API_BASE,
                (string) Credentials::constant(self::REALM_ID),
                BearerAuthorizer::token($token, $this->label()),
                $transport,
                new SystemSleeper(),
            );
        }
        $realm = (new CredentialTokens(self::TOKENS))->get()?->extra(self::REALM) ?? throw new SourceError('QuickBooks Online is not connected: use "Connect to QuickBooks Online" on the settings page.');

        return QuickBooksOnlineReader::connect(self::apiHost(), $realm, BearerAuthorizer::oauth($this->connection()->client($transport)), $transport, new SystemSleeper());
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
            self::CLIENT_ID => 'Intuit app client ID',
            self::CLIENT_SECRET => 'Intuit app client secret',
            self::API_HOST => 'API host (blank: production; ' . QuickBooksOnlineReader::SANDBOX_API_BASE . ' for a sandbox company)',
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

    /** The production host, or the sandbox host when the operator entered it; nothing else. */
    private static function apiHost(): string
    {
        $entered = rtrim(trim(Credentials::get(self::API_HOST)), '/');

        return match ($entered) {
            '', QuickBooksOnlineReader::API_BASE => QuickBooksOnlineReader::API_BASE,
            QuickBooksOnlineReader::SANDBOX_API_BASE => QuickBooksOnlineReader::SANDBOX_API_BASE,
            default => throw new SourceError('The QuickBooks Online API host must be blank or ' . QuickBooksOnlineReader::SANDBOX_API_BASE . '.'),
        };
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
            ClientAuth::Basic,
        ), [], [self::REALM]);
    }
}
