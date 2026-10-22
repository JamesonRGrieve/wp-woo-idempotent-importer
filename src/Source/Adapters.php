<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

use IdempotentImporter\Source\BigCommerce\BigCommerceAdapter;
use IdempotentImporter\Source\Ecwid\EcwidAdapter;
use IdempotentImporter\Source\Etsy\EtsyAdapter;
use IdempotentImporter\Source\FreshBooks\FreshBooksAdapter;
use IdempotentImporter\Source\HubSpot\HubSpotAdapter;
use IdempotentImporter\Source\Lightspeed\LightspeedAdapter;
use IdempotentImporter\Source\Magento\MagentoAdapter;
use IdempotentImporter\Source\Pipedrive\PipedriveAdapter;
use IdempotentImporter\Source\Salesforce\SalesforceAdapter;
use IdempotentImporter\Source\Shopify\ShopifyAdapter;
use IdempotentImporter\Source\SquareOnline\SquareOnlineAdapter;
use IdempotentImporter\Source\Squarespace\SquarespaceAdapter;
use IdempotentImporter\Source\Wave\WaveAdapter;
use IdempotentImporter\Source\Webflow\WebflowAdapter;
use IdempotentImporter\Source\Wix\WixAdapter;
use IdempotentImporter\Source\Zoho\ZohoAdapter;

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
            new LightspeedAdapter(),
            new WebflowAdapter(),
            new HubSpotAdapter(),
            new SalesforceAdapter(),
            new PipedriveAdapter(),
            new ZohoAdapter(),
            new FreshBooksAdapter(),
            new WaveAdapter(),
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
