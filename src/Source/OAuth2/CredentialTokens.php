<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\OAuth2;

use IdempotentImporter\Config\Credentials;

/**
 * A connected account's tokens kept with the plugin's other credentials, as JSON under one name. Written by the
 * connect flow and each refresh, never injected from wp-config or the environment (they change as they are
 * used).
 */
final readonly class CredentialTokens implements TokenStore
{
    public function __construct(private string $name) {}

    public function get(): ?TokenSet
    {
        $stored = Credentials::get($this->name);
        if ($stored === '') {
            return null;
        }
        $decoded = json_decode($stored, true);

        return is_array($decoded) ? TokenSet::fromArray($decoded) : null;
    }

    public function save(TokenSet $tokens): void
    {
        Credentials::save($this->name, json_encode($tokens->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
