<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\OAuth2;

use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\SourceError;

/**
 * Requests as a connected account or with a token the operator issued: `Authorization: Bearer <token>`, from
 * OAuth (refreshed before it expires, and once more when the vendor rejects it) or from a static token (a
 * private-app or site token, which has nothing to renew), plus any fixed headers the vendor wants.
 */
final readonly class BearerAuthorizer implements RequestAuthorizer
{
    /** @param array<string, string> $headers */
    private function __construct(
        private ?OAuth2Client $oauth,
        #[\SensitiveParameter]
        private ?string $staticToken,
        private array $headers,
    ) {}

    /** @param array<string, string> $headers */
    public static function oauth(OAuth2Client $oauth, array $headers = []): self
    {
        return new self($oauth, null, $headers);
    }

    /** @param array<string, string> $headers */
    public static function token(#[\SensitiveParameter] string $token, string $label, array $headers = []): self
    {
        if ($token === '') {
            throw new SourceError("{$label} is not configured: enter an access token or connect the account.");
        }

        return new self(null, $token, $headers);
    }

    public function headers(): array
    {
        return [...$this->headers, 'Authorization' => 'Bearer ' . ($this->staticToken ?? $this->client()->current()->accessToken)];
    }

    public function renew(): bool
    {
        if ($this->oauth === null) {
            return false;
        }
        $this->oauth->refresh();

        return true;
    }

    /** What the vendor said alongside the tokens (null for a static token). */
    public function extra(string $name): ?string
    {
        return $this->oauth?->current()->extra($name);
    }

    private function client(): OAuth2Client
    {
        return $this->oauth ?? throw new SourceError('No access token.');
    }
}
