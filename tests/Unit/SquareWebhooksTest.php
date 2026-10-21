<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Shopify\ShopifyWebhooks;
use IdempotentImporter\Source\SquareOnline\SquareWebhooks;
use IdempotentImporter\Source\WebhookEvent;
use PHPUnit\Framework\TestCase;

final class SquareWebhooksTest extends TestCase
{
    /** Square's published example: signature key, notification URL, body and its signature. */
    private const KEY = 'Ibxx_5AKakO-3qeNVR61Dw';
    private const URL = 'https://webhook.site/679a4f3a-dcfa-49ee-bac5-9d0edad886b9';
    private const BODY = '{"merchant_id":"MLEFBHHSJGVHD","type":"webhooks.test_notification","event_id":"ac3ac95b-f97d-458c-a6e6-18981597e05f","created_at":"2022-07-13T20:30:59.037339943Z","data":{"type":"webhooks","id":"bc368e64-01aa-407e-b46e-3231809b1129"}}';
    private const SIGNATURE = 'GF4YkrJgGBDZ9NIYbNXBnMzqb2HoL4RW/S6vkZ9/2N4=';

    public function testTheSignatureCoversTheUrlAndTheBody(): void
    {
        $square = new SquareWebhooks(static fn(): string => self::KEY);

        self::assertTrue($square->isAuthentic(self::BODY, self::header(self::SIGNATURE), self::URL));
        self::assertFalse($square->isAuthentic(self::BODY, self::header(self::SIGNATURE), self::URL . '/'), 'another URL');
        self::assertFalse($square->isAuthentic(self::BODY . ' ', self::header(self::SIGNATURE), self::URL), 'another body');
        self::assertFalse($square->isAuthentic(self::BODY, self::header(''), self::URL), 'unsigned');
        self::assertFalse((new SquareWebhooks(static fn(): string => ''))->isAuthentic(self::BODY, self::header(self::SIGNATURE), self::URL), 'no key configured');
    }

    public function testOrderPaymentAndRefundEventsRereadTheOrderAndItsReturns(): void
    {
        $square = new SquareWebhooks(static fn(): string => self::KEY);
        $orderAndReturn = static fn(string $id): array => [
            new WebhookEvent(Kind::Order, "gid://square_online/Order/{$id}", false),
            new WebhookEvent(Kind::Return, "gid://square_online/Return/{$id}", false),
        ];

        self::assertEquals($orderAndReturn('ORDER_4'), $square->events(self::header(''), self::event('order-updated')));
        self::assertEquals($orderAndReturn('ORDER_4'), $square->events(self::header(''), self::event('refund-created')), "a refund's (return) order");
        self::assertEquals($orderAndReturn('ORDER_1'), $square->events(self::header(''), self::event('payment-updated')), "a payment's order");
        self::assertEquals(
            [new WebhookEvent(Kind::Customer, 'gid://square_online/Customer/CUST_GRACE', true)],
            $square->events(self::header(''), self::event('customer-deleted')),
        );
        self::assertSame([], $square->events(self::header(''), ['type' => 'catalog.version.updated', 'data' => []]), 'catalog changes wait for a run');
        self::assertSame([], $square->events(self::header(''), ['type' => 'payment.updated', 'data' => ['object' => ['payment' => []]]]), 'a payment with no order');
    }

    public function testShopifyStillAuthenticatesByItsOwnHeader(): void
    {
        $body = '{"id":301}';
        $shopify = new ShopifyWebhooks(static fn(): string => 'shh');
        $signed = static fn(string $name): string => match ($name) {
            'x-shopify-hmac-sha256' => base64_encode(hash_hmac('sha256', $body, 'shh', true)),
            'x-shopify-topic' => 'products/update',
            default => '',
        };

        self::assertTrue($shopify->isAuthentic($body, $signed, 'https://example.test/ignored'));
        self::assertEquals([new WebhookEvent(Kind::Product, 'gid://shopify/Product/301', false)], $shopify->events($signed, ['id' => 301]));
    }

    /** @return \Closure(string): string */
    private static function header(string $signature): \Closure
    {
        return static fn(string $name): string => $name === SquareWebhooks::SIGNATURE_HEADER ? $signature : '';
    }

    /** @return array<array-key, mixed> a recorded notification (validated against Square's event schemas) */
    private static function event(string $name): array
    {
        $event = json_decode((string) file_get_contents(dirname(__DIR__) . "/fixtures/webhooks/square_online/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
        $body = is_array($event) ? ($event['body'] ?? null) : null;

        return is_array($body) ? $body : self::fail("{$name} has no body");
    }
}
