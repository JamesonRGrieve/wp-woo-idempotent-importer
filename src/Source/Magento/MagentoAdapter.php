<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Magento;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\Magento\Mapping\CategoryMapper;
use IdempotentImporter\Source\Magento\Mapping\CustomerMapper;
use IdempotentImporter\Source\Magento\Mapping\DiscountMapper;
use IdempotentImporter\Source\Magento\Mapping\OrderMapper;
use IdempotentImporter\Source\Magento\Mapping\PageMapper;
use IdempotentImporter\Source\Magento\Mapping\ProductMapper;
use IdempotentImporter\Source\Magento\Mapping\ShopMapper;
use IdempotentImporter\Source\SourceReader;

/**
 * Magento Open Source / Adobe Commerce 2.4, through its REST API for one store view: the store's settings,
 * categories, products (simple, virtual, downloadable and configurable with their child products), customers,
 * cart price rules with their coupons, orders with their credit memos and shipments, and CMS pages. Old
 * storefront URLs (url key + .html) 301 to the mirrored objects. The operator creates an integration with
 * read access (System → Integrations), lets the store accept its access token as a bearer token, and enters
 * the store URL, the store view code and the token. Magento Open Source sends no webhooks; scheduled runs
 * keep the mirror current.
 */
final class MagentoAdapter implements Adapter
{
    public const ID = 'magento';
    public const STORE_URL = 'IIM_MAGENTO_STORE_URL';
    public const STORE_CODE = 'IIM_MAGENTO_STORE_CODE';
    public const ACCESS_TOKEN = 'IIM_MAGENTO_ACCESS_TOKEN';
    /** Test harness only: points the client at a local fake Magento. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_MAGENTO_ENDPOINT';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Magento / Adobe Commerce';
    }

    public function kinds(): array
    {
        return [Kind::Shop, Kind::Collection, Kind::Product, Kind::Customer, Kind::Discount, Kind::Order, Kind::Page];
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
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        return MagentoReader::connect(
            Credentials::constant(self::ENDPOINT_OVERRIDE) ?? Credentials::get(self::STORE_URL),
            Credentials::get(self::STORE_CODE),
            Credentials::get(self::ACCESS_TOKEN),
            $transport,
            new SystemSleeper(),
        );
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function filterHint(): ?string
    {
        return null;
    }

    public function credentialFields(): array
    {
        return [
            self::STORE_URL => 'Magento store URL (https://shop.example)',
            self::STORE_CODE => 'Magento store view code (blank for "default")',
            self::ACCESS_TOKEN => 'Magento integration access token',
        ];
    }
}
