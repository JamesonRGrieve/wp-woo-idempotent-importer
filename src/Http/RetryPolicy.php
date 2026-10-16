<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Http;

/**
 * When a source API's response is worth retrying: rate limiting (429) and server errors (5xx), after the
 * server's Retry-After when it sends one, else an exponential backoff. Shared by every source client.
 */
final class RetryPolicy
{
    public const MAX_ATTEMPTS = 6;
    public const BACKOFF_BASE_SECONDS = 1.0;

    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_SERVER_ERROR = 500;

    /** Seconds to wait before attempt $attempt + 1, or null when the response is not a retryable failure. */
    public static function wait(HttpResponse $response, int $attempt): ?float
    {
        if ($response->status !== self::HTTP_TOO_MANY_REQUESTS && $response->status < self::HTTP_SERVER_ERROR) {
            return null;
        }
        $retryAfter = $response->retryAfter !== null && is_numeric($response->retryAfter) ? (float) $response->retryAfter : null;

        return $retryAfter ?? self::BACKOFF_BASE_SECONDS * (2 ** ($attempt - 1));
    }
}
