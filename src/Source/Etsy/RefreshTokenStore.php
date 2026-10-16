<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy;

/** Where the shop owner's OAuth refresh token lives. Etsy issues a new one on every refresh. */
interface RefreshTokenStore
{
    /** The current refresh token, or '' when the shop hasn't been connected. */
    public function get(): string;

    public function save(#[\SensitiveParameter] string $refreshToken): void;
}
