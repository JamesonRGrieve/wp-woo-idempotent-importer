<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

use IdempotentImporter\Source\BigCommerce\BigCommerceAdapter;
use IdempotentImporter\Source\Ecwid\EcwidAdapter;
use IdempotentImporter\Source\Etsy\EtsyAdapter;
use IdempotentImporter\Source\Magento\MagentoAdapter;
use IdempotentImporter\Source\Shopify\ShopifyAdapter;
use IdempotentImporter\Source\SquareOnline\SquareOnlineAdapter;
use IdempotentImporter\Source\Squarespace\SquarespaceAdapter;
use IdempotentImporter\Source\Wix\WixAdapter;

/** Every source platform the importer knows, built or deferred. The single list of adapters. */
final class Adapters
{
    public const DEFAULT = ShopifyAdapter::ID;

    /** @return array<string, Adapter> keyed by adapter id, in display order */
    public static function all(): array
    {
        $byId = [];
        foreach ([
            new ShopifyAdapter(),
            new EtsyAdapter(),
            new SquarespaceAdapter(),
            new WixAdapter(),
            new BigCommerceAdapter(),
            new SquareOnlineAdapter(),
            new EcwidAdapter(),
            new MagentoAdapter(),
        ] as $adapter) {
            $byId[$adapter->id()] = $adapter;
        }

        return $byId;
    }

    public static function exists(string $id): bool
    {
        return isset(self::all()[$id]);
    }

    public static function for(string $id): Adapter
    {
        return self::all()[$id] ?? throw new SourceError("Unknown source platform: {$id}");
    }
}
