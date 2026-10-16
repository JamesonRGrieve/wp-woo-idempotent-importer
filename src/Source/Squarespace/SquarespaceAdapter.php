<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Squarespace;

use DateTimeImmutable;
use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\Squarespace\Mapping\DiscountMapper;
use IdempotentImporter\Source\Squarespace\Mapping\OrderMapper;
use IdempotentImporter\Source\Squarespace\Mapping\ProductMapper;
use IdempotentImporter\Source\Squarespace\Mapping\ProfileMapper;
use IdempotentImporter\Source\Squarespace\Mapping\StorePageMapper;
use IdempotentImporter\Source\Squarespace\Mapping\WebsiteMapper;

/**
 * Squarespace, through its Commerce APIs: the website's store settings, store pages, products with their
 * variants and images, customer profiles, promo-code discounts, and orders with their payments, refunds,
 * taxes and fulfillments. Old product and store-page URLs 301 to the mirrored objects. The operator creates
 * an API key (Settings → Developer API keys) with read-only access to Orders, Products, Inventory,
 * Transactions, Profiles and Discounts and enters it. Pages, blog posts and site styling have no API (the
 * site's WordPress export covers them). Squarespace's webhooks aren't used; scheduled runs keep the mirror
 * current.
 */
final class SquarespaceAdapter implements Adapter
{
    public const ID = 'squarespace';
    public const API_KEY = 'IIM_SQUARESPACE_API_KEY';
    /** Test harness only: points the client at a local fake Squarespace. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_SQUARESPACE_ENDPOINT';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Squarespace';
    }

    public function isDeferred(): bool
    {
        return false;
    }

    public function kinds(): array
    {
        return [Kind::Shop, Kind::Collection, Kind::Product, Kind::Customer, Kind::Discount, Kind::Order];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new WebsiteMapper(),
            new StorePageMapper(),
            new ProductMapper($settings->generateMissingSkus),
            new ProfileMapper(),
            new DiscountMapper(),
            new OrderMapper(),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        return SquarespaceReader::connect(
            Credentials::constant(self::ENDPOINT_OVERRIDE) ?? SquarespaceReader::API_BASE,
            Credentials::get(self::API_KEY),
            $transport,
            new SystemSleeper(),
            new DateTimeImmutable(),
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
        return [self::API_KEY => 'Squarespace API key'];
    }
}
