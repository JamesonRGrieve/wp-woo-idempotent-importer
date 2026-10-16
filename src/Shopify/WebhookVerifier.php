<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Shopify;

/** Verifies Shopify's X-Shopify-Hmac-Sha256 header: base64(HMAC-SHA256(raw body, app client secret)). */
final class WebhookVerifier
{
    public function __construct(#[\SensitiveParameter] private readonly string $secret) {}

    public function isAuthentic(string $rawBody, string $hmacHeader): bool
    {
        if ($this->secret === '' || $hmacHeader === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $this->secret, true));

        return hash_equals($expected, $hmacHeader);
    }

    public function __debugInfo(): array
    {
        return ['secret' => '[redacted]'];
    }
}
