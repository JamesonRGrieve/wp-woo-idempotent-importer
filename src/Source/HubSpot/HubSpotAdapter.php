<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\HubSpot;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\ConnectsByOAuth;
use IdempotentImporter\Source\HubSpot\Mapping\CompanyMapper;
use IdempotentImporter\Source\HubSpot\Mapping\ContactMapper;
use IdempotentImporter\Source\HubSpot\Mapping\CustomerMapper;
use IdempotentImporter\Source\HubSpot\Mapping\EngagementMapper;
use IdempotentImporter\Source\HubSpot\Mapping\ListMapper;
use IdempotentImporter\Source\HubSpot\Mapping\OrderMapper;
use IdempotentImporter\Source\HubSpot\Mapping\ProductMapper;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\OAuth2\OAuthApp;
use IdempotentImporter\Source\OAuth2\OAuthConnection;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\WebhookReceiver;

/**
 * HubSpot, through the CRM API v3: companies, contact lists and segments, contacts with their companies, lists
 * and custom properties, and their notes, calls, emails and meetings, into FluentCRM; the product library, the
 * contacts who placed orders, and commerce orders with their line items, into WooCommerce. The operator creates
 * a private app (or connects an OAuth app) with the read scopes listed in coverage/hubspot.md. Scheduled runs
 * keep the mirror current.
 */
final class HubSpotAdapter implements Adapter, ConnectsByOAuth
{
    public const ID = 'hubspot';
    public const ACCESS_TOKEN = 'IIM_HUBSPOT_ACCESS_TOKEN';
    public const CLIENT_ID = 'IIM_HUBSPOT_CLIENT_ID';
    public const CLIENT_SECRET = 'IIM_HUBSPOT_CLIENT_SECRET';
    public const TOKENS = 'IIM_HUBSPOT_OAUTH_TOKENS';
    /** Test harness only: points the client at a local fake HubSpot. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_HUBSPOT_ENDPOINT';
    /** The OAuth endpoints of the CRM API's published document (components.securitySchemes.oauth2). */
    public const AUTHORIZE_URL = 'https://app.hubspot.com/oauth/authorize';
    public const TOKEN_PATH = '/oauth/v1/token';
    /** The read scopes the endpoints the adapter calls require (their `security` in the published documents). */
    public const SCOPES = [
        'crm.objects.contacts.read', 'crm.objects.companies.read', 'crm.lists.read', 'crm.objects.notes.read',
        'crm.objects.calls.read', 'crm.objects.emails.read', 'crm.objects.meetings.read', 'e-commerce',
        'crm.objects.line_items.read', 'crm.objects.orders.read', 'crm.schemas.contacts.read', 'crm.schemas.companies.read',
        'crm.schemas.line_items.read', 'crm.schemas.orders.read', 'crm.schemas.notes.read', 'crm.schemas.calls.read',
        'crm.schemas.emails.read', 'crm.schemas.meetings.read',
    ];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'HubSpot';
    }

    public function kinds(): array
    {
        return [Kind::Product, Kind::Customer, Kind::CrmCompany, Kind::CrmList, Kind::Contact, Kind::CrmNote, Kind::Order];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new ProductMapper($settings->generateMissingSkus),
            new CustomerMapper(),
            new CompanyMapper(),
            new ListMapper(),
            new ContactMapper(),
            new EngagementMapper(),
            new OrderMapper(),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        $token = Credentials::get(self::ACCESS_TOKEN);

        return HubSpotReader::connect(
            $this->apiBase(),
            $token !== '' ? BearerAuthorizer::token($token, $this->label()) : BearerAuthorizer::oauth($this->connection()->client($transport)),
            $transport,
            new SystemSleeper(),
        );
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
            self::ACCESS_TOKEN => 'HubSpot private app access token (or connect an OAuth app below)',
            self::CLIENT_ID => 'HubSpot OAuth app client ID',
            self::CLIENT_SECRET => 'HubSpot OAuth app client secret',
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

    private function apiBase(): string
    {
        return Credentials::constant(self::ENDPOINT_OVERRIDE) ?? HubSpotReader::API_BASE;
    }

    private function connection(): OAuthConnection
    {
        return new OAuthConnection(self::TOKENS, fn(): OAuthApp => new OAuthApp(
            $this->label(),
            self::AUTHORIZE_URL,
            $this->apiBase() . self::TOKEN_PATH,
            Credentials::get(self::CLIENT_ID),
            Credentials::get(self::CLIENT_SECRET),
            self::SCOPES,
        ));
    }
}
