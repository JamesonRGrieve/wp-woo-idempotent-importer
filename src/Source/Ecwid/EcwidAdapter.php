<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Ecwid;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\Ecwid\Mapping\CategoryMapper;
use IdempotentImporter\Source\Ecwid\Mapping\CustomerMapper;
use IdempotentImporter\Source\Ecwid\Mapping\DiscountMapper;
use IdempotentImporter\Source\Ecwid\Mapping\OrderMapper;
use IdempotentImporter\Source\Ecwid\Mapping\ProductMapper;
use IdempotentImporter\Source\Ecwid\Mapping\ReviewMapper;
use IdempotentImporter\Source\Ecwid\Mapping\ShopMapper;
use IdempotentImporter\Source\Ecwid\Mapping\UrlRedirectMapper;
use IdempotentImporter\Source\SourceReader;

/**
 * Ecwid by Lightspeed (also sold as Lightspeed eCom E-Series), through its REST API v3: the store profile
 * (settings, company, formats and its legal pages), categories, products with their variations and images,
 * customers, discount coupons, orders with their items, taxes, refunds and tracking, Instant Site redirects
 * and product reviews. Old storefront URLs 301 to the mirrored objects. The operator creates a custom app in
 * the Ecwid admin with read scopes and enters the store id and the app's secret access token. Webhooks need a
 * public app's endpoint and aren't used; scheduled runs keep the mirror current.
 */
final class EcwidAdapter implements Adapter
{
    public const ID = 'ecwid';
    public const STORE_ID = 'IIM_ECWID_STORE_ID';
    public const SECRET_TOKEN = 'IIM_ECWID_SECRET_TOKEN';
    /** Test harness only: points the client at a local fake Ecwid. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_ECWID_ENDPOINT';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Ecwid (Lightspeed)';
    }

    public function kinds(): array
    {
        return [Kind::Shop, Kind::Collection, Kind::Product, Kind::Customer, Kind::Discount, Kind::Order, Kind::UrlRedirect, Kind::Review];
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
            new UrlRedirectMapper(),
            new ReviewMapper(),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        return EcwidReader::connect(
            Credentials::constant(self::ENDPOINT_OVERRIDE) ?? EcwidReader::API_HOST,
            trim(Credentials::get(self::STORE_ID)),
            Credentials::get(self::SECRET_TOKEN),
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
            self::STORE_ID => 'Ecwid store ID',
            self::SECRET_TOKEN => 'Ecwid custom app secret access token (secret_…)',
        ];
    }
}
