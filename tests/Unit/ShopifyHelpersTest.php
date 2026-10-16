<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Shopify\Gid;
use IdempotentImporter\Shopify\SearchQuery;
use IdempotentImporter\Shopify\ShopifyError;
use IdempotentImporter\Shopify\WebhookTopics;
use IdempotentImporter\Shopify\WebhookVerifier;
use PHPUnit\Framework\TestCase;

final class ShopifyHelpersTest extends TestCase
{
    public function testSearchQueryCombinesWindowAndFilter(): void
    {
        $since = new DateTimeImmutable('2026-09-01T08:00:00-06:00');

        self::assertSame("updated_at:>='2026-09-01T14:00:00Z' AND (status:active)", SearchQuery::build($since, ' status:active '));
        self::assertSame("updated_at:>='2026-09-01T14:00:00Z'", SearchQuery::build($since, ''));
        self::assertSame('(vendor:Acme)', SearchQuery::build(null, 'vendor:Acme'));
        self::assertNull(SearchQuery::build(null, ''));
    }

    public function testGid(): void
    {
        $gid = Gid::parse('gid://shopify/Product/123');

        self::assertSame('Product', $gid->type);
        self::assertSame('123', $gid->id);
        self::assertSame('gid://shopify/Order/9', Gid::of('Order', '9'));
        $this->expectException(ShopifyError::class);
        Gid::parse('123');
    }

    public function testWebhookHmacIsVerifiedInConstantTime(): void
    {
        $body = '{"id":1}';
        $hmac = base64_encode(hash_hmac('sha256', $body, 'shh', true));
        $verifier = new WebhookVerifier('shh');

        self::assertTrue($verifier->isAuthentic($body, $hmac));
        self::assertFalse($verifier->isAuthentic($body . ' ', $hmac));
        self::assertFalse($verifier->isAuthentic($body, ''));
        self::assertFalse((new WebhookVerifier(''))->isAuthentic($body, $hmac));
    }

    public function testWebhookTopicsResolveTheObject(): void
    {
        $update = WebhookTopics::resolve('products/update', ['id' => 301]);
        self::assertNotNull($update);
        self::assertSame(Kind::Product, $update->kind);
        self::assertSame('gid://shopify/Product/301', $update->gid);
        self::assertFalse($update->deletion);

        $refund = WebhookTopics::resolve('refunds/create', ['id' => 9, 'order_id' => 801]);
        self::assertSame('gid://shopify/Order/801', $refund?->gid);

        $deleted = WebhookTopics::resolve('discounts/delete', ['admin_graphql_api_id' => 'gid://shopify/DiscountCodeNode/7']);
        self::assertSame('gid://shopify/DiscountCodeNode/7', $deleted?->gid);
        self::assertTrue($deleted->deletion);

        $stock = WebhookTopics::resolve('inventory_levels/update', ['inventory_item_id' => 55]);
        self::assertSame('gid://shopify/InventoryItem/55', $stock?->gid);

        self::assertNull(WebhookTopics::resolve('app/uninstalled', ['id' => 1]));
        self::assertNull(WebhookTopics::resolve('products/update', []));
    }
}
