<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\OAuth2;

/**
 * The tokens of a connected account: the access token, the refresh token (null when the vendor issues tokens
 * that don't expire), when the access token expires (Unix time; null when it doesn't), and what else the
 * vendor said that requests need (Salesforce's `instance_url`, Zoho's `api_domain`, QuickBooks' `realmId`).
 */
final readonly class TokenSet
{
    /** @param array<string, string> $extras */
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        #[\SensitiveParameter]
        public ?string $refreshToken,
        public ?int $expiresAt,
        public array $extras = [],
    ) {}

    public function extra(string $name): ?string
    {
        $value = $this->extras[$name] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /** Whether the access token is past (or within $marginSeconds of) its expiry at $now. */
    public function isExpired(float $now, int $marginSeconds): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt - $marginSeconds;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['access_token' => $this->accessToken, 'refresh_token' => $this->refreshToken, 'expires_at' => $this->expiresAt, 'extras' => $this->extras];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $access = $data['access_token'] ?? null;
        if (!is_string($access) || $access === '') {
            return null;
        }
        $refresh = $data['refresh_token'] ?? null;
        $expires = $data['expires_at'] ?? null;
        $extras = [];
        foreach (is_array($data['extras'] ?? null) ? $data['extras'] : [] as $name => $value) {
            if (is_string($value)) {
                $extras[(string) $name] = $value;
            }
        }

        return new self($access, is_string($refresh) && $refresh !== '' ? $refresh : null, is_int($expires) ? $expires : null, $extras);
    }
}
