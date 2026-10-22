<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\ConnectsByOAuth;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\OAuth2\OAuthApp;
use IdempotentImporter\Source\OAuth2\OAuthConnection;
use IdempotentImporter\Source\Salesforce\Mapping\AccountMapper;
use IdempotentImporter\Source\Salesforce\Mapping\ActivityMapper;
use IdempotentImporter\Source\Salesforce\Mapping\BoardMapper;
use IdempotentImporter\Source\Salesforce\Mapping\CampaignMapper;
use IdempotentImporter\Source\Salesforce\Mapping\ContactMapper;
use IdempotentImporter\Source\Salesforce\Mapping\CustomerMapper;
use IdempotentImporter\Source\Salesforce\Mapping\OpportunityMapper;
use IdempotentImporter\Source\Salesforce\Mapping\OrderMapper;
use IdempotentImporter\Source\Salesforce\Mapping\ProductMapper;
use IdempotentImporter\Source\Salesforce\Mapping\TaskMapper;
use IdempotentImporter\Source\Salesforce\Mapping\TopicMapper;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\WebhookReceiver;

/**
 * Salesforce, through its REST API: accounts, campaigns (as lists), topics (as tags), contacts and leads, and
 * their logged calls, emails, events and notes, into FluentCRM; opportunities with their stages, and tasks, into
 * FluentBoards; products with their standard prices, the
 * contacts orders are billed to, and orders with their items, into WooCommerce. The operator creates a
 * connected app (OAuth web server flow with PKCE, scopes `api refresh_token`) and connects the org; the token
 * response names the org's instance URL. Scheduled runs keep the mirror current.
 */
final class SalesforceAdapter implements Adapter, ConnectsByOAuth
{
    public const ID = 'salesforce';
    public const CLIENT_ID = 'IIM_SALESFORCE_CLIENT_ID';
    public const CLIENT_SECRET = 'IIM_SALESFORCE_CLIENT_SECRET';
    /** The login host: https://login.salesforce.com, https://test.salesforce.com for a sandbox, or the org's My Domain. */
    public const LOGIN_URL = 'IIM_SALESFORCE_LOGIN_URL';
    public const TOKENS = 'IIM_SALESFORCE_OAUTH_TOKENS';
    /** An access token and its instance URL issued elsewhere (the Salesforce CLI, a client-credentials flow). */
    public const ACCESS_TOKEN = 'IIM_SALESFORCE_ACCESS_TOKEN';
    public const INSTANCE_URL = 'IIM_SALESFORCE_INSTANCE_URL';
    public const DEFAULT_LOGIN_URL = 'https://login.salesforce.com';
    public const AUTHORIZE_PATH = '/services/oauth2/authorize';
    public const TOKEN_PATH = '/services/oauth2/token';
    public const SCOPES = ['api', 'refresh_token'];
    /** The token response field naming the org's API host. */
    public const INSTANCE_URL_FIELD = 'instance_url';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Salesforce';
    }

    public function kinds(): array
    {
        return [Kind::Product, Kind::Customer, Kind::CrmCompany, Kind::CrmList, Kind::CrmTag, Kind::Contact, Kind::CrmNote, Kind::CrmPipeline, Kind::CrmDeal, Kind::CrmTask, Kind::Order];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new ProductMapper($settings->generateMissingSkus),
            new CustomerMapper(),
            new AccountMapper(),
            new CampaignMapper(),
            new TopicMapper(),
            new ContactMapper(),
            new ActivityMapper(),
            new BoardMapper(),
            new OpportunityMapper(),
            new TaskMapper(),
            new OrderMapper(),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        $token = Credentials::get(self::ACCESS_TOKEN);
        if ($token !== '') {
            return SalesforceReader::connect(Credentials::get(self::INSTANCE_URL), BearerAuthorizer::token($token, $this->label()), $transport, new SystemSleeper());
        }
        $authorizer = BearerAuthorizer::oauth($this->connection()->client($transport));
        $instance = $authorizer->extra(self::INSTANCE_URL_FIELD) ?? throw new SourceError('Salesforce is not connected: use "Connect to Salesforce" on the settings page.');

        return SalesforceReader::connect($instance, $authorizer, $transport, new SystemSleeper());
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
            self::CLIENT_ID => 'Salesforce connected app consumer key',
            self::CLIENT_SECRET => 'Salesforce connected app consumer secret',
            self::LOGIN_URL => 'Salesforce login URL (blank: https://login.salesforce.com; a sandbox or My Domain URL otherwise)',
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
        $login = rtrim(trim(Credentials::get(self::LOGIN_URL)) ?: self::DEFAULT_LOGIN_URL, '/');

        return new OAuthConnection(self::TOKENS, fn(): OAuthApp => new OAuthApp(
            $this->label(),
            $login . self::AUTHORIZE_PATH,
            $login . self::TOKEN_PATH,
            Credentials::get(self::CLIENT_ID),
            Credentials::get(self::CLIENT_SECRET),
            self::SCOPES,
            pkce: true,
        ), [self::INSTANCE_URL_FIELD]);
    }
}
