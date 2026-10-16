<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

/** OAuth 2 PKCE (RFC 7636): a random code verifier and its S256 challenge. */
final class Pkce
{
    public const METHOD = 'S256';

    private const VERIFIER_BYTES = 32;

    /** A fresh code verifier (43 URL-safe characters). */
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(self::VERIFIER_BYTES));
    }

    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
