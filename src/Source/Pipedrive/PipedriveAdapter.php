<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive;

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
use IdempotentImporter\Source\Pipedrive\Mapping\ActivityMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\CategoryMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\FilterMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\LabelMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\OrganizationMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\PersonMapper;
use IdempotentImporter\Source\Pipedrive\Mapping\ProductMapper;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\WebhookReceiver;

/**
 * Pipedrive, through its API v1 and v2: organizations, person filters (as lists), person labels (as tags),
 * persons with their custom fields, and notes and done activities, into FluentCRM; product categories and
 * products with their variations, into WooCommerce. The operator enters a personal API token, or connects a
 * Pipedrive app (OAuth; the token response names the company's API domain). Scheduled runs keep the mirror
 * current.
 */
final class PipedriveAdapter implements Adapter, ConnectsByOAuth
{
    public const ID = 'pipedrive';
    public const API_TOKEN = 'IIM_PIPEDRIVE_API_TOKEN';
    public const CLIENT_ID = 'IIM_PIPEDRIVE_CLIENT_ID';
    public const CLIENT_SECRET = 'IIM_PIPEDRIVE_CLIENT_SECRET';
    public const TOKENS = 'IIM_PIPEDRIVE_OAUTH_TOKENS';
    /** Test harness only: points the client at a local fake Pipedrive. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_PIPEDRIVE_ENDPOINT';
    /** The OAuth endpoints of the published documents (components.securitySchemes.oauth2). */
    public const AUTHORIZE_URL = 'https://oauth.pipedrive.com/oauth/authorize';
    public const TOKEN_URL = 'https://oauth.pipedrive.com/oauth/token';
    /** The token response field naming the company's API host. */
    public const API_DOMAIN_FIELD = 'api_domain';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Pipedrive';
    }

    public function kinds(): array
    {
        return [Kind::Collection, Kind::Product, Kind::CrmCompany, Kind::CrmList, Kind::CrmTag, Kind::Contact, Kind::CrmNote];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new CategoryMapper(),
            new ProductMapper($settings->generateMissingSkus),
            new OrganizationMapper(),
            new FilterMapper(),
            new LabelMapper(),
            new PersonMapper(),
            new ActivityMapper(),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        $override = Credentials::constant(self::ENDPOINT_OVERRIDE);
        $token = Credentials::get(self::API_TOKEN);
        if ($token !== '') {
            return PipedriveReader::connect($override ?? PipedriveReader::API_BASE, new ApiTokenAuthorizer($token), $transport, new SystemSleeper());
        }
        $authorizer = BearerAuthorizer::oauth($this->connection()->client($transport));
        $domain = $override ?? $authorizer->extra(self::API_DOMAIN_FIELD) ?? throw new SourceError('Pipedrive is not connected: use "Connect to Pipedrive" on the settings page.');

        return PipedriveReader::connect($domain, $authorizer, $transport, new SystemSleeper());
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
            self::API_TOKEN => 'Pipedrive personal API token (or connect an app below)',
            self::CLIENT_ID => 'Pipedrive app client ID',
            self::CLIENT_SECRET => 'Pipedrive app client secret',
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

    /** Pipedrive apps declare their scopes in the app itself; the authorize URL names none. */
    private function connection(): OAuthConnection
    {
        return new OAuthConnection(self::TOKENS, fn(): OAuthApp => new OAuthApp(
            $this->label(),
            self::AUTHORIZE_URL,
            self::TOKEN_URL,
            Credentials::get(self::CLIENT_ID),
            Credentials::get(self::CLIENT_SECRET),
            [],
            ClientAuth::Basic,
        ), [self::API_DOMAIN_FIELD]);
    }
}
