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

/**
 * Shopify, through the Admin GraphQL API. Reviews have no Admin API; they come from the Product Reviews
 * CSV export (`wp idempotent-import reviews`), so Review has a mapper but isn't read by runs.
 */
final class ShopifyAdapter implements Adapter
{
    public const ID = 'shopify';
    public const ACCESS_TOKEN = 'IIM_SHOPIFY_ACCESS_TOKEN';
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

    public function isDeferred(): bool
    {
        return false;
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
        $token = Credentials::get(self::ACCESS_TOKEN);
        $override = Credentials::constant(self::ENDPOINT_OVERRIDE);
        $connection = $override !== null
            ? new Connection($override, $token)
            : Connection::forStore($settings->storeDomain, $token);

        return new GraphQlClient($connection, QueryLibrary::bundled(), $transport, new SystemSleeper());
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function filterHint(): string
    {
        return 'Shopify search, e.g. status:active';
    }

    public function credentialFields(): array
    {
        return [
            self::ACCESS_TOKEN => 'Admin API access token',
            self::WEBHOOK_SECRET => 'Webhook signing secret (app client secret)',
        ];
    }
}
