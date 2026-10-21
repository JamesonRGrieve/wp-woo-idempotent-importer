<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\BigCommerce\Mapping\ArticleMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\BlogMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\CategoryMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\CustomerMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\DiscountMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\GiftCertificateMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\OrderMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\PageMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\ProductMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\ReviewMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\ShopMapper;
use IdempotentImporter\Source\BigCommerce\Mapping\UrlRedirectMapper;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\WebhookReceiver;

/**
 * BigCommerce, through the REST Management API (v3, and v2 where BigCommerce has no v3 yet): the store's
 * settings, categories, products with their variants, images and custom fields, customers, coupons and
 * coupon promotions, orders with their lines, shipping, taxes, shipments and refunds, content pages, the
 * blog and its posts, storefront redirects, and product reviews. Old storefront URLs 301 to the mirrored
 * objects. The operator creates a store-level API account (read-only scopes) and enters the store hash and
 * the account's access token. BigCommerce's webhooks aren't used; scheduled runs keep the mirror current.
 */
final class BigCommerceAdapter implements Adapter
{
    public const ID = 'bigcommerce';
    public const STORE_HASH = 'IIM_BIGCOMMERCE_STORE_HASH';
    public const ACCESS_TOKEN = 'IIM_BIGCOMMERCE_ACCESS_TOKEN';
    /** Test harness only: points the client at a local fake BigCommerce. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_BIGCOMMERCE_ENDPOINT';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'BigCommerce';
    }

    public function kinds(): array
    {
        return [
            Kind::Shop, Kind::Collection, Kind::Product, Kind::Customer, Kind::Discount, Kind::Order, Kind::GiftCard,
            Kind::Page, Kind::Blog, Kind::Article, Kind::UrlRedirect, Kind::Review,
        ];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new ShopMapper(),
            new CategoryMapper(),
            new ProductMapper($settings->generateMissingSkus),
            new CustomerMapper(),
            new DiscountMapper(),
            new OrderMapper(),
            new PageMapper(),
            new BlogMapper(),
            new ArticleMapper(),
            new UrlRedirectMapper(),
            new ReviewMapper(),
            new GiftCertificateMapper($settings),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        return BigCommerceReader::connect(
            Credentials::constant(self::ENDPOINT_OVERRIDE) ?? BigCommerceReader::API_BASE,
            Credentials::get(self::STORE_HASH),
            Credentials::get(self::ACCESS_TOKEN),
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
            self::STORE_HASH => 'BigCommerce store hash',
            self::ACCESS_TOKEN => 'BigCommerce API account access token',
        ];
    }
}
