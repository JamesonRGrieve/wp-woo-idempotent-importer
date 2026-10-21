<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow;

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
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\Webflow\Mapping\ArticleMapper;
use IdempotentImporter\Source\Webflow\Mapping\AssetMapper;
use IdempotentImporter\Source\Webflow\Mapping\BlogMapper;
use IdempotentImporter\Source\Webflow\Mapping\CategoryMapper;
use IdempotentImporter\Source\Webflow\Mapping\CollectionMapper;
use IdempotentImporter\Source\Webflow\Mapping\CustomerMapper;
use IdempotentImporter\Source\Webflow\Mapping\ItemMapper;
use IdempotentImporter\Source\Webflow\Mapping\OrderMapper;
use IdempotentImporter\Source\Webflow\Mapping\PageMapper;
use IdempotentImporter\Source\Webflow\Mapping\ProductMapper;
use IdempotentImporter\Source\Webflow\Mapping\RedirectMapper;
use IdempotentImporter\Source\Webflow\Mapping\SiteMapper;
use IdempotentImporter\Source\WebhookReceiver;

/**
 * Webflow, through the Data API v2: the site's settings, assets, e-commerce categories, products with their
 * SKUs and inventory, buyers (gathered from orders), orders, static pages, the blog collection the operator
 * names, every other CMS collection as a custom post type with its items, and 301 redirects. Old product,
 * category, page and item URLs redirect to the mirrored objects. The operator connects a Webflow OAuth app (or
 * enters a site API token) with read access to sites, CMS, pages, assets and e-commerce. Scheduled runs keep
 * the mirror current.
 */
final class WebflowAdapter implements Adapter, ConnectsByOAuth
{
    public const ID = 'webflow';
    public const SITE_ID = 'IIM_WEBFLOW_SITE_ID';
    public const BLOG_COLLECTION = 'IIM_WEBFLOW_BLOG_COLLECTION_ID';
    public const API_TOKEN = 'IIM_WEBFLOW_API_TOKEN';
    public const CLIENT_ID = 'IIM_WEBFLOW_CLIENT_ID';
    public const CLIENT_SECRET = 'IIM_WEBFLOW_CLIENT_SECRET';
    public const TOKENS = 'IIM_WEBFLOW_OAUTH_TOKENS';
    /** Test harness only: points the client at a local fake Webflow. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_WEBFLOW_ENDPOINT';
    public const AUTHORIZE_URL = 'https://webflow.com/oauth/authorize';
    /** developers.webflow.com/data/reference/oauth-app ("Request an access token"). */
    public const TOKEN_URL = 'https://api.webflow.com/oauth/access_token';
    /** Read-only scopes for everything the adapter reads (each endpoint's "Required scope"). */
    public const SCOPES = ['sites:read', 'cms:read', 'pages:read', 'assets:read', 'ecommerce:read'];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Webflow';
    }

    public function kinds(): array
    {
        return [Kind::Shop, Kind::File, Kind::Collection, Kind::Product, Kind::Customer, Kind::Order, Kind::Page, Kind::Blog, Kind::Article, Kind::ContentType, Kind::ContentItem, Kind::UrlRedirect];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new SiteMapper(),
            new AssetMapper(),
            new CategoryMapper(),
            new ProductMapper($settings->generateMissingSkus),
            new CustomerMapper(),
            new OrderMapper(),
            new PageMapper(),
            new BlogMapper(),
            new ArticleMapper(),
            new CollectionMapper(),
            new ItemMapper(),
            new RedirectMapper(),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        $token = Credentials::get(self::API_TOKEN);
        $authorizer = $token !== '' ? BearerAuthorizer::token($token, $this->label()) : BearerAuthorizer::oauth($this->connection()->client($transport));

        return WebflowReader::connect(
            Credentials::constant(self::ENDPOINT_OVERRIDE) ?? WebflowReader::API_BASE,
            trim(Credentials::get(self::SITE_ID)),
            trim(Credentials::get(self::BLOG_COLLECTION)),
            $authorizer,
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
            self::SITE_ID => 'Webflow site ID',
            self::BLOG_COLLECTION => 'Webflow collection ID to mirror as the blog (blank: none)',
            self::API_TOKEN => 'Webflow site API token (or connect an OAuth app below)',
            self::CLIENT_ID => 'Webflow OAuth app client ID',
            self::CLIENT_SECRET => 'Webflow OAuth app client secret',
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
            (Credentials::constant(self::ENDPOINT_OVERRIDE) === null ? self::TOKEN_URL : Credentials::constant(self::ENDPOINT_OVERRIDE) . '/oauth/access_token'),
            Credentials::get(self::CLIENT_ID),
            Credentials::get(self::CLIENT_SECRET),
            self::SCOPES,
        ));
    }
}
