<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\OAuth2;

use Closure;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Http\SystemSleeper;

/**
 * What an adapter that connects by OAuth shares with every other one: its app (built when needed, so a site
 * without the app's credentials still works), where its tokens are kept, which token response fields and
 * redirect parameters it keeps, and the connect flow over OAuth2Client.
 */
final readonly class OAuthConnection
{
    /**
     * @param Closure(): OAuthApp $app
     * @param list<string> $keptFields token response fields requests need
     * @param list<string> $callbackParameters redirect parameters requests need
     */
    public function __construct(
        private string $tokensCredential,
        private Closure $app,
        private array $keptFields = [],
        private array $callbackParameters = [],
    ) {}

    public function client(HttpTransport $transport, Sleeper $clock = new SystemSleeper()): OAuth2Client
    {
        return new OAuth2Client(($this->app)(), $transport, new CredentialTokens($this->tokensCredential), $clock, $this->keptFields);
    }

    public function authorizationUrl(HttpTransport $transport, string $redirectUri, string $state, string $verifier): string
    {
        return $this->client($transport)->authorizationUrl($redirectUri, $state, $verifier);
    }

    /** @return list<string> */
    public function callbackParameters(): array
    {
        return $this->callbackParameters;
    }

    /** @param array<string, string> $callback */
    public function complete(HttpTransport $transport, string $code, string $verifier, string $redirectUri, array $callback): void
    {
        $this->client($transport)->exchange($code, $verifier, $redirectUri, array_intersect_key($callback, array_flip($this->callbackParameters)));
    }

    public function isConnected(): bool
    {
        return (new CredentialTokens($this->tokensCredential))->get() !== null;
    }
}
