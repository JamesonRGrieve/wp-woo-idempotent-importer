<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\SquareOnline;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\SquareOnline\Mapping\Fields;
use IdempotentImporter\Source\WebhookEvent;
use IdempotentImporter\Source\WebhookReceiver;

/**
 * Square's webhooks. Square signs each notification with the subscription's signature key:
 * `x-square-hmacsha256-signature` = base64(HMAC-SHA256(key, notification URL + raw body))
 * (https://developer.squareup.com/docs/webhooks/step3validate). Order, payment and refund events name the
 * order they change (re-read as an order and as a return, since a refund or return order is both), and
 * customer events the customer. Catalog and stock changes are left to scheduled runs: their events name
 * no single item.
 */
final class SquareWebhooks implements WebhookReceiver
{
    public const SIGNATURE_HEADER = 'x-square-hmacsha256-signature';
    private const HMAC_ALGORITHM = 'sha256';
    /** Event type → where its order id is in `data` (null: `data.id` itself). */
    private const ORDER_EVENTS = [
        'order.created' => null,
        'order.updated' => null,
        'order.fulfillment.updated' => null,
        'payment.created' => ['object', 'payment', 'order_id'],
        'payment.updated' => ['object', 'payment', 'order_id'],
        'refund.created' => ['object', 'refund', 'order_id'],
        'refund.updated' => ['object', 'refund', 'order_id'],
    ];
    /** Customer event type → whether it is a deletion. */
    private const CUSTOMER_EVENTS = ['customer.created' => false, 'customer.updated' => false, 'customer.deleted' => true];

    /** @param \Closure(): string $signatureKey read when a webhook arrives, so a changed key applies at once */
    public function __construct(private readonly \Closure $signatureKey) {}

    public function isAuthentic(string $rawBody, \Closure $header, string $url): bool
    {
        $signature = $header(self::SIGNATURE_HEADER);
        $key = ($this->signatureKey)();
        if ($key === '' || $signature === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac(self::HMAC_ALGORITHM, $url . $rawBody, $key, true));

        return hash_equals($expected, $signature);
    }

    public function events(\Closure $header, array $payload): array
    {
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        if (array_key_exists($type, self::ORDER_EVENTS)) {
            $path = self::ORDER_EVENTS[$type];
            $orderId = $path === null ? ($data['id'] ?? null) : self::at($data, $path);
            if (!is_string($orderId) || $orderId === '') {
                return [];
            }

            return [
                new WebhookEvent(Kind::Order, Fields::id('Order', $orderId), false),
                new WebhookEvent(Kind::Return, Fields::id('Return', $orderId), false),
            ];
        }
        $customerId = $data['id'] ?? null;
        if (isset(self::CUSTOMER_EVENTS[$type]) && is_string($customerId) && $customerId !== '') {
            return [new WebhookEvent(Kind::Customer, Fields::id('Customer', $customerId), self::CUSTOMER_EVENTS[$type])];
        }

        return [];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string> $path
     */
    private static function at(array $data, array $path): mixed
    {
        $value = $data;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
