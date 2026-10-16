<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use IdempotentImporter\Source\Etsy\RefreshTokenStore;

/** A refresh-token store for unit tests: holds the token in memory and counts saves. */
final class InMemoryRefreshTokens implements RefreshTokenStore
{
    public int $saves = 0;

    public function __construct(private string $token = '') {}

    public function get(): string
    {
        return $this->token;
    }

    public function save(#[\SensitiveParameter] string $refreshToken): void
    {
        $this->token = $refreshToken;
        ++$this->saves;
    }
}
