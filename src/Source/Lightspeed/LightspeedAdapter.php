<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\Lightspeed\Mapping\ArticleMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\BlogMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\CategoryMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\CustomerMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\DiscountMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\OrderMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\PageMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\ProductMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\ReviewMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\ShopMapper;
use IdempotentImporter\Source\Lightspeed\Mapping\UrlRedirectMapper;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;

/**
 * Lightspeed eCom (C-Series, formerly SEOshop / WebshopApp), through its REST API: the shop and its website
 * settings, categories, products with their variants, images, categories and brand, customers, discount
 * codes, orders with their lines, shipments and credit notes, text pages, blogs and their articles, URL
 * redirects and product reviews. Old storefront URLs 301 to the mirrored objects. The operator creates an
 * API key (read-only permissions) in the eCom back office and enters its key and secret, the shop's cluster
 * (eu1 or us1) and the shop language whose content is mirrored. Lightspeed Retail (R-Series) and X-Series
 * are other products with other APIs and aren't read. Webhooks aren't used; scheduled runs keep the mirror
 * current.
 */
final class LightspeedAdapter implements Adapter
{
    public const ID = 'lightspeed';
    public const CLUSTER = 'IIM_LIGHTSPEED_CLUSTER';
    public const LANGUAGE = 'IIM_LIGHTSPEED_LANGUAGE';
    public const API_KEY = 'IIM_LIGHTSPEED_API_KEY';
    public const API_SECRET = 'IIM_LIGHTSPEED_API_SECRET';
    /** Test harness only: points the client at a local fake Lightspeed. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_LIGHTSPEED_ENDPOINT';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Lightspeed eCom';
    }

    public function kinds(): array
    {
        return [
            Kind::Shop, Kind::Collection, Kind::Product, Kind::Customer, Kind::Discount, Kind::Order, Kind::Page,
            Kind::Blog, Kind::Article, Kind::UrlRedirect, Kind::Review,
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
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        $cluster = strtolower(trim(Credentials::get(self::CLUSTER)));
        $apiBase = Credentials::constant(self::ENDPOINT_OVERRIDE)
            ?? LightspeedReader::CLUSTERS[$cluster]
            ?? throw new SourceError('Lightspeed eCom is not configured: enter the cluster (' . implode(' or ', array_keys(LightspeedReader::CLUSTERS)) . ').');

        return LightspeedReader::connect(
            $apiBase,
            strtolower(trim(Credentials::get(self::LANGUAGE))),
            Credentials::get(self::API_KEY),
            Credentials::get(self::API_SECRET),
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
            self::CLUSTER => 'Lightspeed eCom cluster (eu1 or us1)',
            self::LANGUAGE => 'Lightspeed eCom shop language code (e.g. en, nl, us)',
            self::API_KEY => 'Lightspeed eCom API key',
            self::API_SECRET => 'Lightspeed eCom API secret',
        ];
    }
}
