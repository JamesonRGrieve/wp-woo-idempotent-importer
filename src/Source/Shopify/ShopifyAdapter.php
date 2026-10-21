<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\Shopify\Mapping\ArticleMapper;
use IdempotentImporter\Source\Shopify\Mapping\BlogMapper;
use IdempotentImporter\Source\Shopify\Mapping\CollectionMapper;
use IdempotentImporter\Source\Shopify\Mapping\CommentMapper;
use IdempotentImporter\Source\Shopify\Mapping\CustomerMapper;
use IdempotentImporter\Source\Shopify\Mapping\DeliveryProfileMapper;
use IdempotentImporter\Source\Shopify\Mapping\DiscountMapper;
use IdempotentImporter\Source\Shopify\Mapping\DraftOrderMapper;
use IdempotentImporter\Source\Shopify\Mapping\FileMapper;
use IdempotentImporter\Source\Shopify\Mapping\MenuMapper;
use IdempotentImporter\Source\Shopify\Mapping\OrderMapper;
use IdempotentImporter\Source\Shopify\Mapping\PageMapper;
use IdempotentImporter\Source\Shopify\Mapping\ProductMapper;
use IdempotentImporter\Source\Shopify\Mapping\ReviewMapper;
use IdempotentImporter\Source\Shopify\Mapping\ShopMapper;
use IdempotentImporter\Source\Shopify\Mapping\UrlRedirectMapper;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\WebhookReceiver;

/**
 * Shopify, through the Admin GraphQL API. Reviews have no Admin API; they come from the Product Reviews
 * CSV export (`wp idempotent-import reviews`), so Review has a mapper but isn't read by runs.
 */
final class ShopifyAdapter implements Adapter
{
    public const ID = 'shopify';
    /** A Dev Dashboard app's client ID and secret: the way to connect since Shopify closed "Develop apps" (2026-01-01). */
    public const CLIENT_ID = 'IIM_SHOPIFY_CLIENT_ID';
    public const CLIENT_SECRET = 'IIM_SHOPIFY_CLIENT_SECRET';
    /** A legacy custom app's permanent token; used instead of the client credentials when set. */
    public const ACCESS_TOKEN = 'IIM_SHOPIFY_ACCESS_TOKEN';
    /** A legacy custom app's separate webhook signing secret; otherwise webhooks are signed with the client secret. */
    public const WEBHOOK_SECRET = 'IIM_SHOPIFY_WEBHOOK_SECRET';
    /** Test harness only: points the client at a local fake Shopify. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_SHOPIFY_ENDPOINT';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Shopify';
    }

    public function kinds(): array
    {
        return [
            Kind::Shop, Kind::File, Kind::Collection, Kind::Product, Kind::Customer, Kind::Discount, Kind::Order,
            Kind::DraftOrder, Kind::Page, Kind::Blog, Kind::Article, Kind::Comment, Kind::Menu, Kind::UrlRedirect,
            Kind::DeliveryProfile,
        ];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new ShopMapper(),
            new FileMapper(),
            new CollectionMapper(),
            new ProductMapper($settings->generateMissingSkus),
            new CustomerMapper(),
            new DiscountMapper(),
            new OrderMapper(),
            new DraftOrderMapper(),
            new PageMapper(),
            new BlogMapper(),
            new ArticleMapper(),
            new CommentMapper(),
            new MenuMapper(),
            new UrlRedirectMapper(),
            new DeliveryProfileMapper(),
            new ReviewMapper(),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        return new Reader($this->client($settings, $transport));
    }

    /** The Admin API client (also used to manage webhook subscriptions). Throws SourceError when unconfigured. */
    public function client(Settings $settings, HttpTransport $transport): GraphQlClient
    {
        $override = Credentials::constant(self::ENDPOINT_OVERRIDE);
        $connection = $override !== null ? new Connection($override) : Connection::forStore($settings->storeDomain);

        return new GraphQlClient($connection, QueryLibrary::bundled(), self::authorizer($connection, $transport), $transport, new SystemSleeper());
    }

    /** The secret Shopify signs this store's webhooks with: a legacy app's own signing secret, else the client secret. */
    public static function webhookSecret(): string
    {
        $legacy = Credentials::get(self::WEBHOOK_SECRET);

        return $legacy !== '' ? $legacy : Credentials::get(self::CLIENT_SECRET);
    }

    /** A legacy permanent token when one is set, else the Dev Dashboard app's client credentials. */
    private static function authorizer(Connection $connection, HttpTransport $transport): RequestAuthorizer
    {
        $token = Credentials::get(self::ACCESS_TOKEN);
        if ($token !== '') {
            return new ShopifyTokenAuthorizer($token);
        }

        return new ClientCredentialsAuthorizer($connection->tokenUrl(), Credentials::get(self::CLIENT_ID), Credentials::get(self::CLIENT_SECRET), $transport);
    }

    public function webhooks(): WebhookReceiver
    {
        return new ShopifyWebhooks(self::webhookSecret(...));
    }

    public function filterHint(): string
    {
        return 'Shopify search, e.g. status:active';
    }

    public function credentialFields(): array
    {
        return [
            self::CLIENT_ID => 'Client ID (Dev Dashboard app)',
            self::CLIENT_SECRET => 'Client secret',
            self::ACCESS_TOKEN => 'Admin API access token (legacy custom apps only)',
            self::WEBHOOK_SECRET => 'Webhook signing secret (legacy custom apps only)',
        ];
    }
}
