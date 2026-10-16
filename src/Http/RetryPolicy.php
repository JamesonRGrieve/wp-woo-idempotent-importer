<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Http;

/**
 * When a source API's response is worth retrying: rate limiting (429) and server errors (5xx), after the
 * server's Retry-After when it sends one, else after the rate-limit window's reset time when it sends that
 * (in milliseconds), else (for rate limiting) the source's documented cool-down when it has one, else an
 * exponential backoff capped at MAX_BACKOFF_SECONDS. Shared by every source client. A client sits out a
 * wait of up to MAX_INLINE_WAIT_SECONDS inside the request; a longer one, or running out of attempts, pauses
 * the run instead (Source\RetryLater), so a step never outlives its PHP time limit and a source that keeps
 * pushing back isn't hammered.
 */
final class RetryPolicy
{
    public const MAX_ATTEMPTS = 6;
    public const BACKOFF_BASE_SECONDS = 1.0;
    public const MAX_BACKOFF_SECONDS = 16.0;
    public const MAX_INLINE_WAIT_SECONDS = 30.0;

    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_SERVER_ERROR = 500;
    private const MILLISECONDS_PER_SECOND = 1000;

    /**
     * Seconds to wait before attempt $attempt + 1, or null when the response is not a retryable failure.
     * $rateLimitCooldownSeconds is how long a source that sends no timing hint refuses requests after a 429.
     */
    public static function wait(HttpResponse $response, int $attempt, ?float $rateLimitCooldownSeconds = null): ?float
    {
        if ($response->status !== self::HTTP_TOO_MANY_REQUESTS && $response->status < self::HTTP_SERVER_ERROR) {
            return null;
        }

        return self::seconds($response->retryAfter)
            ?? self::milliseconds($response->rateLimitResetMs)
            ?? ($response->status === self::HTTP_TOO_MANY_REQUESTS ? $rateLimitCooldownSeconds : null)
            ?? self::backoff($attempt);
    }

    /** The exponential backoff before attempt $attempt + 1: 1, 2, 4, 8, 16, 16, … seconds. */
    public static function backoff(int $attempt): float
    {
        return min(self::MAX_BACKOFF_SECONDS, self::BACKOFF_BASE_SECONDS * (2 ** max(0, $attempt - 1)));
    }

    /** Whether a client should sit out $wait itself before attempt $attempt + 1, rather than pause the run. */
    public static function waitsInline(float $wait, int $attempt): bool
    {
        return $attempt < self::MAX_ATTEMPTS && $wait <= self::MAX_INLINE_WAIT_SECONDS;
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
