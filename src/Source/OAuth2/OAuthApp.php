<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\OAuth2;

use IdempotentImporter\Source\SourceError;

/**
 * One vendor's OAuth 2 app as the operator registered it, and how that vendor runs the authorization-code
 * grant (RFC 6749 §4.1): where the owner approves access and where codes and refresh tokens are traded, the
 * read scopes asked for, whether the app proves itself with HTTP Basic or in the form, whether PKCE (RFC
 * 7636) is used, and any extra parameters the vendor's authorize page takes (offline access, consent).
 */
final readonly class OAuthApp
{
    /**
     * @param list<string> $scopes
     * @param array<string, string> $authorizeParams
     */
    public function __construct(
        public string $label,
        public string $authorizeUrl,
        public string $tokenUrl,
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public array $scopes,
        public ClientAuth $clientAuth = ClientAuth::Form,
        public bool $pkce = false,
        public array $authorizeParams = [],
        public string $scopeSeparator = ' ',
    ) {
        if ($clientId === '' || $clientSecret === '') {
            throw new SourceError("{$label} is not configured: enter the app's client ID and client secret.");
        }
    }

    /** The same app with its token URL elsewhere (a vendor whose token host is per region or per account). */
    public function withTokenUrl(string $tokenUrl): self
    {
        return new self($this->label, $this->authorizeUrl, $tokenUrl, $this->clientId, $this->clientSecret, $this->scopes, $this->clientAuth, $this->pkce, $this->authorizeParams, $this->scopeSeparator);
    }
}
