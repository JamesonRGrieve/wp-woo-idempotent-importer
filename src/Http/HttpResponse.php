<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Http;

/**
 * One response, with the server's rate-limit hints as sent: `Retry-After` (seconds), the milliseconds until
 * the rate-limit window resets for APIs that send that instead (BigCommerce's `X-Rate-Limit-Time-Reset-Ms`),
 * and every response header (names lower-cased) for a source's own RateLimit headers.
 */
final readonly class HttpResponse
{
    /** @param array<string, string> $headers keyed by lower-cased name */
    public function __construct(
        public int $status,
        public string $body,
        public ?string $retryAfter = null,
        public ?string $rateLimitResetMs = null,
        public array $headers = [],
    ) {}

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        return $value === null || $value === '' ? null : $value;
    }
}
