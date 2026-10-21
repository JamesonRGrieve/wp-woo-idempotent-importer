<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Source\WebhookReceiver;
use IdempotentImporter\Source\Wix\Mapping\ArticleMapper;
use IdempotentImporter\Source\Wix\Mapping\BlogMapper;
use IdempotentImporter\Source\Wix\Mapping\CategoryMapper;
use IdempotentImporter\Source\Wix\Mapping\ContactMapper;
use IdempotentImporter\Source\Wix\Mapping\CouponMapper;
use IdempotentImporter\Source\Wix\Mapping\OrderMapper;
use IdempotentImporter\Source\Wix\Mapping\ProductMapper;
use IdempotentImporter\Source\Wix\Mapping\RedirectMapper;
use IdempotentImporter\Source\Wix\Mapping\ReviewMapper;
use IdempotentImporter\Source\Wix\Mapping\SiteMapper;

/**
 * Wix, through its REST APIs with an account API key: the site's properties, the Stores catalog (V3, or
 * the original V1 catalog, whichever the site runs) with its categories or collections, products,
 * variants, media and inventory, contacts, coupons, eCommerce orders with their payments, refunds and
 * fulfillments, the blog with its categories and posts, the site's URL redirects, and product reviews. Old
 * Wix product, category and post URLs 301 to the mirrored objects. The operator generates an API key
 * (read scopes for those APIs) in the account's API Keys Manager and enters it with the site id. Wix's
 * webhooks aren't used; scheduled runs keep the mirror current.
 */
final class WixAdapter implements Adapter
{
    public const ID = 'wix';
    public const API_KEY = 'IIM_WIX_API_KEY';
    public const SITE_ID = 'IIM_WIX_SITE_ID';
    /** Test harness only: points the client at a local fake Wix. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_WIX_ENDPOINT';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Wix';
    }

    public function kinds(): array
    {
        return [
            Kind::Shop, Kind::Collection, Kind::Product, Kind::Customer, Kind::Discount, Kind::Order, Kind::Blog,
            Kind::Article, Kind::UrlRedirect, Kind::Review,
        ];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new SiteMapper(),
            new CategoryMapper(),
            new ProductMapper($settings->generateMissingSkus),
            new ContactMapper(),
            new CouponMapper(),
            new OrderMapper(),
            new BlogMapper(),
            new ArticleMapper(),
            new RedirectMapper(),
            new ReviewMapper(),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        return WixReader::connect(
            Credentials::constant(self::ENDPOINT_OVERRIDE) ?? WixReader::API_BASE,
            Credentials::get(self::API_KEY),
            Credentials::get(self::SITE_ID),
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
            self::API_KEY => 'Wix API key',
            self::SITE_ID => 'Wix site id',
        ];
    }
}
