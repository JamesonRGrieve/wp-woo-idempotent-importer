<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use IdempotentImporter\Http\HttpResponse;

/**
 * Etsy as the tests see it: the OAuth token endpoint answers with a token for the shop owner, everything
 * else comes from the Etsy fixtures (FixtureResponder).
 */
final class EtsyResponder implements Responder
{
    public const OWNER_USER_ID = 7001;
    public const ACCESS_TOKEN = self::OWNER_USER_ID . '.fixture-access-token';
    public const REFRESH_TOKEN = 'fixture-refresh-token';
    public const TOKEN_PATH = '/v3/public/oauth/token';

    private const HTTP_OK = 200;
    private const TOKEN_LIFETIME_SECONDS = 3600;

    private readonly FixtureResponder $fixtures;

    public function __construct(string $state)
    {
        $this->fixtures = new FixtureResponder('etsy', $state);
    }

    public function respond(string $method, string $url, string $body): HttpResponse
    {
        if ($method === 'POST' && parse_url($url, PHP_URL_PATH) === self::TOKEN_PATH) {
            return new HttpResponse(self::HTTP_OK, json_encode([
                'access_token' => self::ACCESS_TOKEN,
                'token_type' => 'Bearer',
                'expires_in' => self::TOKEN_LIFETIME_SECONDS,
                'refresh_token' => self::REFRESH_TOKEN,
            ], JSON_THROW_ON_ERROR));
        }

        return $this->fixtures->respond($method, $url, $body);
    }
}
