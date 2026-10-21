<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\OAuth2;

/** Where a connected account's tokens live. Vendors that rotate refresh tokens replace them on every refresh. */
interface TokenStore
{
    /** The tokens, or null when no account has been connected. */
    public function get(): ?TokenSet;

    public function save(TokenSet $tokens): void;
}
