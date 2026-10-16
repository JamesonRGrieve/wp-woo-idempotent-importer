<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy;

use IdempotentImporter\Source\SourceError;

/**
 * Where and as which app to reach Etsy's Open API v3. The API key header is `keystring:shared_secret`,
 * as the spec's `api_key` scheme requires; the OAuth client id is the keystring alone.
 */
final readonly class EtsyConnection
{
    public const API_BASE = 'https://openapi.etsy.com';
    public const TOKEN_PATH = '/v3/public/oauth/token';
    public const AUTHORIZE_URL = 'https://www.etsy.com/oauth/connect';

    public function __construct(
        public string $apiBase,
        public string $keystring,
        #[\SensitiveParameter]
        public string $sharedSecret,
    ) {
        if ($keystring === '' || $sharedSecret === '') {
            throw new SourceError('Etsy is not configured: enter the app keystring and shared secret.');
        }
    }

    public function apiKey(): string
    {
        return "{$this->keystring}:{$this->sharedSecret}";
    }

    public function tokenUrl(): string
    {
        return $this->apiBase . self::TOKEN_PATH;
    }
}
