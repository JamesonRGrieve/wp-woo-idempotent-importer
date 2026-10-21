<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use IdempotentImporter\Source\OAuth2\TokenSet;
use IdempotentImporter\Source\OAuth2\TokenStore;

/** A connected account's tokens, kept in memory, with every save recorded. */
final class InMemoryTokens implements TokenStore
{
    /** @var list<TokenSet> */
    public array $saved = [];

    public function __construct(private ?TokenSet $tokens = null) {}

    public function get(): ?TokenSet
    {
        return $this->tokens;
    }

    public function save(TokenSet $tokens): void
    {
        $this->tokens = $tokens;
        $this->saved[] = $tokens;
    }
}
