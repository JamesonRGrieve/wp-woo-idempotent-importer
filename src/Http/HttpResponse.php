<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Http;

/**
 * One response, with the server's rate-limit hints as sent: `Retry-After` (seconds), and the milliseconds
 * until the rate-limit window resets for APIs that send that instead (BigCommerce's
 * `X-Rate-Limit-Time-Reset-Ms`).
 */
final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public ?string $retryAfter = null,
        public ?string $rateLimitResetMs = null,
    ) {}
}
