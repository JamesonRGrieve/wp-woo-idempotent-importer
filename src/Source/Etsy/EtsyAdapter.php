<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\ConnectsByOAuth;
use IdempotentImporter\Source\Etsy\Mapping\BuyerMapper;
use IdempotentImporter\Source\Etsy\Mapping\ListingMapper;
use IdempotentImporter\Source\Etsy\Mapping\ReceiptMapper;
use IdempotentImporter\Source\Etsy\Mapping\ReviewMapper;
use IdempotentImporter\Source\Etsy\Mapping\SectionMapper;
use IdempotentImporter\Source\Etsy\Mapping\ShopMapper;
use IdempotentImporter\Source\SourceReader;

/**
 * Etsy, through Open API v3: the shop and its policies, sections (as categories), listings in every
 * state, buyers, receipts and reviews. Etsy has no webhooks, so it is kept current by scheduled runs.
 * The operator registers an Etsy app (keystring + shared secret) and connects the shop with OAuth.
 */
final class EtsyAdapter implements Adapter, ConnectsByOAuth
{
    public const ID = 'etsy';
    public const KEYSTRING = 'IIM_ETSY_KEYSTRING';
    public const SHARED_SECRET = 'IIM_ETSY_SHARED_SECRET';
    public const REFRESH_TOKEN = 'IIM_ETSY_REFRESH_TOKEN';
    /** Test harness only: points the client at a local fake Etsy. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_ETSY_ENDPOINT';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Etsy';
    }

    public function isDeferred(): bool
    {
        return false;
    }

    public function kinds(): array
    {
        return [Kind::Shop, Kind::Collection, Kind::Product, Kind::Customer, Kind::Order, Kind::Review];
    }

    public function mappers(Settings $settings): array
    {
        return [
            new ShopMapper(),
            new SectionMapper(),
            new ListingMapper($settings->generateMissingSkus),
            new BuyerMapper(),
            new ReceiptMapper(),
            new ReviewMapper(),
        ];
    }

    public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        $connection = $this->connection();

        return new EtsyReader(new EtsyClient($connection, $this->oauth($connection, $transport), $transport, new SystemSleeper()));
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
            self::KEYSTRING => 'Etsy app keystring',
            self::SHARED_SECRET => 'Etsy app shared secret',
        ];
    }

    public function authorizationUrl(HttpTransport $transport, string $redirectUri, string $state, string $verifier): string
    {
        $connection = $this->connection();

        return $this->oauth($connection, $transport)->authorizationUrl($redirectUri, $state, $verifier);
    }

    public function complete(HttpTransport $transport, string $code, string $verifier, string $redirectUri): void
    {
        $connection = $this->connection();
        $this->oauth($connection, $transport)->exchange($code, $verifier, $redirectUri);
    }

    public function isConnected(): bool
    {
        return Credentials::get(self::REFRESH_TOKEN) !== '';
    }

    private function connection(): EtsyConnection
    {
        return new EtsyConnection(
            Credentials::constant(self::ENDPOINT_OVERRIDE) ?? EtsyConnection::API_BASE,
            Credentials::get(self::KEYSTRING),
            Credentials::get(self::SHARED_SECRET),
        );
    }

    private function oauth(EtsyConnection $connection, HttpTransport $transport): OAuth
    {
        return new OAuth($connection, $transport, new CredentialRefreshTokens(self::REFRESH_TOKEN));
    }
}
