<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Http;

/**
 * When a source API's response is worth retrying: rate limiting (429) and server errors (5xx), after the
 * server's Retry-After when it sends one, else after the rate-limit window's reset time when it sends that
 * (in milliseconds), else an exponential backoff. Shared by every source client.
 */
final class RetryPolicy
{
    public const MAX_ATTEMPTS = 6;
    public const BACKOFF_BASE_SECONDS = 1.0;

    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_SERVER_ERROR = 500;
    private const MILLISECONDS_PER_SECOND = 1000;

    /** Seconds to wait before attempt $attempt + 1, or null when the response is not a retryable failure. */
    public static function wait(HttpResponse $response, int $attempt): ?float
    {
        if ($response->status !== self::HTTP_TOO_MANY_REQUESTS && $response->status < self::HTTP_SERVER_ERROR) {
            return null;
        }

        return self::seconds($response->retryAfter)
            ?? self::milliseconds($response->rateLimitResetMs)
            ?? self::BACKOFF_BASE_SECONDS * (2 ** ($attempt - 1));
    }

    private static function seconds(?string $value): ?float
    {
        return $value !== null && is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }

    private static function milliseconds(?string $value): ?float
    {
        $milliseconds = self::seconds($value);

        return $milliseconds === null ? null : $milliseconds / self::MILLISECONDS_PER_SECOND;
    }
}
