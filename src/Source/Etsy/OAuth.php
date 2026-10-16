<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy;

use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Source\Pkce;
use IdempotentImporter\Source\SourceError;
use JsonException;

/**
 * Etsy's OAuth 2 authorization-code flow with PKCE (S256): the connect URL, the code exchange and the
 * refresh. Every token response carries a new refresh token, which is saved before the access token is
 * used. Tokens never appear in errors.
 */
final class OAuth
{
    /** Read-only scopes: listings, receipts and payments, private shop data, buyer emails. */
    public const SCOPES = ['listings_r', 'transactions_r', 'shops_r', 'email_r'];
    public const REQUEST_TIMEOUT_SECONDS = 30;

    private const HTTP_OK = 200;

    public function __construct(
        private readonly EtsyConnection $connection,
        private readonly HttpTransport $transport,
        private readonly RefreshTokenStore $refreshTokens,
    ) {}

    public function authorizationUrl(string $redirectUri, string $state, string $verifier): string
    {
        return EtsyConnection::AUTHORIZE_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->connection->keystring,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => Pkce::challenge($verifier),
            'code_challenge_method' => Pkce::METHOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Completes the connect flow: trades the authorization code for tokens and keeps the refresh token. */
    public function exchange(string $code, string $verifier, string $redirectUri): string
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'client_id' => $this->connection->keystring,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $verifier,
        ]);
    }

    /** A new access token from the stored refresh token (which is replaced by the new one). */
    public function refresh(): string
    {
        $refreshToken = $this->refreshTokens->get();
        if ($refreshToken === '') {
            throw new SourceError('Etsy is not connected: use "Connect to Etsy" on the settings page.');
        }

        return $this->token([
            'grant_type' => 'refresh_token',
            'client_id' => $this->connection->keystring,
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Posts a token request; saves the new refresh token and returns the access token.
     *
     * @param array<string, string> $form
     */
    private function token(array $form): string
    {
        $response = $this->transport->post(
            $this->connection->tokenUrl(),
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query($form, '', '&', PHP_QUERY_RFC3986),
            self::REQUEST_TIMEOUT_SECONDS,
        );
        if ($response->status !== self::HTTP_OK) {
            throw new SourceError("Etsy refused the token request (HTTP {$response->status}); reconnect the shop.");
        }
        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SourceError('Etsy returned an unreadable token response.');
        }
        $access = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;
        $refresh = is_array($decoded) ? ($decoded['refresh_token'] ?? null) : null;
        if (!is_string($access) || $access === '' || !is_string($refresh) || $refresh === '') {
            throw new SourceError('Etsy returned a token response without tokens.');
        }
        $this->refreshTokens->save($refresh);

        return $access;
    }
}
