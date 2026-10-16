<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\SquareOnline;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\SquareOnline\Mapping\CategoryMapper;
use IdempotentImporter\Source\SquareOnline\Mapping\CustomerMapper;
use IdempotentImporter\Source\SquareOnline\Mapping\ItemMapper;
use IdempotentImporter\Source\SquareOnline\Mapping\OrderMapper;
use IdempotentImporter\Source\SquareOnline\Mapping\ShopMapper;

/**
 * Square Online, through the Square API: the seller and its main location (with its return policy), catalog
 * categories, items with their variations, options, images and stock at the main location, customers, and
 * orders at every location with their refunds and fulfillments. Square Online's site pages and blog have no
 * public API. The operator enters a personal access token (or an OAuth access token) with read scopes.
 */
final class SquareOnlineAdapter implements Adapter
{
    public const ID = 'square_online';
    public const ACCESS_TOKEN = 'IIM_SQUARE_ONLINE_ACCESS_TOKEN';
    /** Test harness only: points the client at a local fake Square. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_SQUARE_ONLINE_ENDPOINT';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Square Online';
    }

    public function kinds(): array
    {
        return [Kind::Shop, Kind::Collection, Kind::Product, Kind::Customer, Kind::Order];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new ShopMapper(),
            new CategoryMapper(),
            new ItemMapper($settings->generateMissingSkus),
            new CustomerMapper(),
            new OrderMapper(),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        return SquareOnlineReader::connect(
            Credentials::constant(self::ENDPOINT_OVERRIDE) ?? SquareOnlineReader::API_BASE,
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
        return [self::ACCESS_TOKEN => 'Square access token'];
    }
}
