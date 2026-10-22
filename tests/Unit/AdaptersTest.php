<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\Adapters;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Shopify\ShopifyAdapter;
use IdempotentImporter\Source\SourceError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdaptersTest extends TestCase
{
    public function testEveryAdapterIsListedUnderItsOwnId(): void
    {
        $adapters = Adapters::all();

        self::assertSame(['shopify', 'etsy', 'squarespace', 'wix', 'bigcommerce', 'square_online', 'ecwid', 'magento', 'lightspeed', 'webflow', 'hubspot', 'salesforce', 'pipedrive', 'zoho', 'freshbooks'], array_keys($adapters));
        foreach ($adapters as $id => $adapter) {
            self::assertSame($id, $adapter->id());
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $id, 'ids are the host of every identity they mint');
        }
    }

    public function testAnUnknownSourceIsAnError(): void
    {
        self::assertFalse(Adapters::exists('myspace'));
        $this->expectException(SourceError::class);
        Adapters::for('myspace');
    }

    /** @return iterable<string, array{Adapter}> */
    public static function adapters(): iterable
    {
        foreach (Adapters::all() as $id => $adapter) {
            yield $id => [$adapter];
        }
    }

    #[DataProvider('adapters')]
    public function testKindsAreInSyncOrderAndEachHasAMapper(Adapter $adapter): void
    {
        $kinds = $adapter->kinds();
        self::assertNotSame([], $kinds, "{$adapter->id()} reads something");
        self::assertSame(
            array_values(array_filter(Kind::SYNC_ORDER, static fn(Kind $kind): bool => in_array($kind, $kinds, true))),
            $kinds,
        );
        $mapped = array_map(static fn($mapper): Kind => $mapper->kind(), $adapter->mappers(self::settings()));
        foreach ($kinds as $kind) {
            self::assertContains($kind, $mapped, "{$adapter->id()} reads {$kind->value} but has no mapper for it");
        }
        self::assertSame(count($mapped), count(array_unique(array_map(static fn(Kind $kind): string => $kind->value, $mapped))), 'one mapper per kind');
    }

    public function testShopifyReviewsComeFromCsvNotRuns(): void
    {
        $shopify = new ShopifyAdapter();

        self::assertNotContains(Kind::Review, $shopify->kinds());
        self::assertContains(Kind::Review, array_map(static fn($mapper): Kind => $mapper->kind(), $shopify->mappers(self::settings())));
    }

    public function testIdentitiesAreNamespacedBySource(): void
    {
        self::assertSame('gid://etsy/Listing/42', Identity::of('etsy', 'Listing', 42));
        self::assertSame('gid://etsy/', Identity::prefix('etsy'));
        self::assertSame('gid://shopify/', Identity::prefixOf('gid://shopify/Product/1'));
        self::assertSame('gid://etsy/', Identity::prefixOf(Identity::of('etsy', 'Receipt', '9')));
    }

    public function testTheSourcesOwnIdIsReadBackFromAnIdentity(): void
    {
        self::assertSame('9201', Identity::sourceId(Identity::of('ecwid', 'Product', 9201)));
        self::assertSame('CW-1001', Identity::sourceId('gid://ecwid/Order/CW-1001'));
    }

    public function testAnIdentityWithoutAnIdHasNoSourceId(): void
    {
        $this->expectException(SourceError::class);
        Identity::sourceId('gid://ecwid/Product/');
    }

    public function testANonIdentityHasNoSource(): void
    {
        $this->expectException(SourceError::class);
        Identity::prefixOf('review:abc');
    }

    private static function settings(): Settings
    {
        return new Settings(
            source: Adapters::DEFAULT,
            storeDomain: 'fixture.myshopify.com',
            kinds: Kind::SYNC_ORDER,
            filters: [],
            intervalMinutes: 60,
            fullReconcileHours: 24,
            storeSettings: false,
            shippingZones: false,
            redirects: true,
            localiseImages: true,
            cleanHtml: false,
            preserveOrderNumbers: true,
            generateMissingSkus: false,
            defaultAuthor: 0,
            notifyEmail: '',
        );
    }
}
