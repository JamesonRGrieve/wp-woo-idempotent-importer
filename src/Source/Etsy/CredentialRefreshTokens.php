<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy;

use IdempotentImporter\Config\Credentials;

/**
 * The refresh token kept with the plugin's other credentials. Etsy replaces it on every refresh, so it is
 * written by the connect flow and each refresh, never injected from wp-config or the environment.
 */
final class CredentialRefreshTokens implements RefreshTokenStore
{
    public function __construct(private readonly string $name) {}

    public function get(): string
    {
        return Credentials::get($this->name);
    }

    public function save(#[\SensitiveParameter] string $refreshToken): void
    {
        Credentials::save($this->name, $refreshToken);
    }
}
