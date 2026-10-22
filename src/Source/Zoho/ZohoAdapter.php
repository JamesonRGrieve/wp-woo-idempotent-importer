<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\ConnectsByOAuth;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\OAuth2\CredentialTokens;
use IdempotentImporter\Source\OAuth2\OAuthApp;
use IdempotentImporter\Source\OAuth2\OAuthConnection;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\WebhookReceiver;
use IdempotentImporter\Source\Zoho\Mapping\AccountMapper;
use IdempotentImporter\Source\Zoho\Mapping\ActivityMapper;
use IdempotentImporter\Source\Zoho\Mapping\BoardMapper;
use IdempotentImporter\Source\Zoho\Mapping\CampaignMapper;
use IdempotentImporter\Source\Zoho\Mapping\CategoryMapper;
use IdempotentImporter\Source\Zoho\Mapping\ContactMapper;
use IdempotentImporter\Source\Zoho\Mapping\CustomerMapper;
use IdempotentImporter\Source\Zoho\Mapping\DealMapper;
use IdempotentImporter\Source\Zoho\Mapping\OrderMapper;
use IdempotentImporter\Source\Zoho\Mapping\ProductMapper;
use IdempotentImporter\Source\Zoho\Mapping\TagMapper;
use IdempotentImporter\Source\Zoho\Mapping\TaskMapper;

/**
 * Zoho CRM, through its API v8: accounts, campaigns (as lists), tags, contacts and leads with their custom fields,
 * and their notes, calls and meetings, into FluentCRM; deal pipelines with their stages, deals and tasks into
 * FluentBoards; product categories, products, the contacts sales orders
 * name, and sales orders with their items, into WooCommerce. The operator registers a server-based client in
 * the Zoho API console and connects the org (OAuth with offline access); the redirect names the data centre's
 * accounts server, which tokens are traded and refreshed at, and the token response names the API domain.
 */
final class ZohoAdapter implements Adapter, ConnectsByOAuth
{
    public const ID = 'zoho';
    public const CLIENT_ID = 'IIM_ZOHO_CLIENT_ID';
    public const CLIENT_SECRET = 'IIM_ZOHO_CLIENT_SECRET';
    /** The accounts server the operator signs in at: https://accounts.zoho.com, or the data centre's (.eu, .in, …). */
    public const ACCOUNTS_URL = 'IIM_ZOHO_ACCOUNTS_URL';
    public const TOKENS = 'IIM_ZOHO_OAUTH_TOKENS';
    /** Test harness only: an access token and API domain issued elsewhere. Never settable from the admin. */
    public const ACCESS_TOKEN = 'IIM_ZOHO_ACCESS_TOKEN';
    public const API_DOMAIN = 'IIM_ZOHO_API_DOMAIN';
    public const DEFAULT_ACCOUNTS_URL = 'https://accounts.zoho.com';
    public const AUTHORIZE_PATH = '/oauth/v2/auth';
    public const TOKEN_PATH = '/oauth/v2/token';
    /** The authorization header's scheme (zoho.com/crm/developer/docs/api/v8/access-refresh.html). */
    public const SCHEME = 'Zoho-oauthtoken';
    /** Read access to every module, field metadata, tags and the org (the published documents' scopes). */
    public const SCOPES = [
        'ZohoCRM.modules.READ', 'ZohoCRM.modules.notes.READ', 'ZohoCRM.settings.fields.READ', 'ZohoCRM.settings.tags.READ', 'ZohoCRM.org.READ',
        'ZohoCRM.settings.layouts.READ', 'ZohoCRM.settings.pipeline.READ',
    ];
    /** The redirect parameter naming the data centre's accounts server, and the token response field naming the API domain. */
    public const ACCOUNTS_SERVER = 'accounts-server';
    public const API_DOMAIN_FIELD = 'api_domain';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Zoho CRM';
    }

    public function kinds(): array
    {
        return [Kind::Collection, Kind::Product, Kind::Customer, Kind::CrmCompany, Kind::CrmList, Kind::CrmTag, Kind::Contact, Kind::CrmNote, Kind::CrmPipeline, Kind::CrmDeal, Kind::CrmTask, Kind::Order];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new CategoryMapper(),
            new ProductMapper($settings->generateMissingSkus),
            new CustomerMapper(),
            new AccountMapper(),
            new CampaignMapper(),
            new TagMapper(),
            new ContactMapper(),
            new ActivityMapper(),
            new BoardMapper(),
            new DealMapper(),
            new TaskMapper(),
            new OrderMapper(),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        $token = Credentials::constant(self::ACCESS_TOKEN);
        if ($token !== null) {
            return ZohoReader::connect((string) Credentials::constant(self::API_DOMAIN), BearerAuthorizer::token($token, $this->label(), [], self::SCHEME), $transport, new SystemSleeper());
        }
        $authorizer = BearerAuthorizer::oauth($this->connection()->client($transport), [], self::SCHEME);
        $domain = $authorizer->extra(self::API_DOMAIN_FIELD) ?? throw new SourceError('Zoho CRM is not connected: use "Connect to Zoho CRM" on the settings page.');

        return ZohoReader::connect($domain, $authorizer, $transport, new SystemSleeper());
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
            self::CLIENT_ID => 'Zoho API console client ID (server-based application)',
            self::CLIENT_SECRET => 'Zoho API console client secret',
            self::ACCOUNTS_URL => 'Zoho accounts URL (blank: https://accounts.zoho.com; your data centre\'s otherwise)',
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
        $this->connection($callback[self::ACCOUNTS_SERVER] ?? null)->complete($transport, $code, $verifier, $redirectUri, $callback);
    }

    public function isConnected(): bool
    {
        return $this->connection()->isConnected();
    }

    /**
     * The connection: codes and refresh tokens are traded at the accounts server the redirect named (kept with the
     * tokens), else the one the operator entered.
     */
    private function connection(?string $accountsServer = null): OAuthConnection
    {
        $entered = rtrim(trim(Credentials::get(self::ACCOUNTS_URL)) ?: self::DEFAULT_ACCOUNTS_URL, '/');
        $stored = (new CredentialTokens(self::TOKENS))->get()?->extra(self::ACCOUNTS_SERVER);
        $server = rtrim($accountsServer !== null && preg_match('#^https://accounts\.zoho\.[a-z.]+$#', $accountsServer) === 1 ? $accountsServer : ($stored ?? $entered), '/');

        return new OAuthConnection(self::TOKENS, fn(): OAuthApp => new OAuthApp(
            $this->label(),
            $entered . self::AUTHORIZE_PATH,
            $server . self::TOKEN_PATH,
            Credentials::get(self::CLIENT_ID),
            Credentials::get(self::CLIENT_SECRET),
            self::SCOPES,
            authorizeParams: ['access_type' => 'offline', 'prompt' => 'consent'],
            scopeSeparator: ',',
        ), [self::API_DOMAIN_FIELD], [self::ACCOUNTS_SERVER]);
    }
}
