<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use IdempotentImporter\Source\WebhookReceiver;

/**
 * Shopify's webhooks: signed with the app's secret (WebhookVerifier), one object per topic (WebhookTopics).
 * The secret is read when a webhook arrives, so a changed credential applies at once.
 */
final class ShopifyWebhooks implements WebhookReceiver
{
    private const HMAC_HEADER = 'x-shopify-hmac-sha256';
    private const TOPIC_HEADER = 'x-shopify-topic';

    /** @param \Closure(): string $secret */
    public function __construct(private readonly \Closure $secret) {}

    public function isAuthentic(string $rawBody, \Closure $header, string $url): bool
    {
        return (new WebhookVerifier(($this->secret)()))->isAuthentic($rawBody, $header(self::HMAC_HEADER));
    }

    public function events(\Closure $header, array $payload): array
    {
        $event = WebhookTopics::resolve($header(self::TOPIC_HEADER), $payload);

        return $event === null ? [] : [$event];
    }
}
