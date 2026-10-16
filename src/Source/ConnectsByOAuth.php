<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

use IdempotentImporter\Http\HttpTransport;

/**
 * An adapter whose shop owner grants access through an OAuth authorization-code flow (PKCE). The admin
 * page sends the operator to authorizationUrl() and, on return, hands the code to complete(). The caller
 * keeps $state and $verifier between the two.
 */
interface ConnectsByOAuth
{
    /** @throws SourceError when the app credentials aren't configured */
    public function authorizationUrl(HttpTransport $transport, string $redirectUri, string $state, string $verifier): string;

    /** @throws SourceError when the source refuses the code */
    public function complete(HttpTransport $transport, string $code, string $verifier, string $redirectUri): void;

    /** Whether a shop has been connected (a refresh token is held). */
    public function isConnected(): bool;
}
