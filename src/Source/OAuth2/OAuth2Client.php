<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\OAuth2;

use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\Sleeper;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Pkce;
use IdempotentImporter\Source\SourceError;
use JsonException;

/**
 * The authorization-code grant against one vendor (RFC 6749 §4.1, with PKCE where the vendor takes it): the
 * connect URL, the code exchange, and access tokens refreshed shortly before they expire. Every token
 * response is saved before its access token is used, so a vendor that rotates refresh tokens never strands
 * the connection. A refresh the vendor refuses (a revoked or expired grant) is CredentialsRejected: the run
 * stops and scheduled runs hold until the account is connected again. Tokens never appear in errors.
 */
final class OAuth2Client
{
    public const REQUEST_TIMEOUT_SECONDS = 30;
    /** An access token is refreshed this long before it expires, so it doesn't expire mid-request. */
    public const EXPIRY_MARGIN_SECONDS = 120;

    private const HTTP_OK = 200;
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_UNAUTHORIZED = 401;
    /** Where the redirect URI a code was traded with is kept, for a vendor whose refresh names it. */
    private const REDIRECT_URI_EXTRA = 'redirect_uri';

    /** @param list<string> $keptFields token response fields requests need (e.g. `instance_url`), kept with the tokens */
    public function __construct(
        private readonly OAuthApp $app,
        private readonly HttpTransport $transport,
        private readonly TokenStore $tokens,
        private readonly Sleeper $clock,
        private readonly array $keptFields = [],
    ) {}

    public function authorizationUrl(string $redirectUri, string $state, string $verifier): string
    {
        $query = [
            'response_type' => 'code',
            'client_id' => $this->app->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            ...($this->app->scopes === [] ? [] : ['scope' => implode($this->app->scopeSeparator, $this->app->scopes)]),
            ...($this->app->pkce ? ['code_challenge' => Pkce::challenge($verifier), 'code_challenge_method' => Pkce::METHOD] : []),
            ...$this->app->authorizeParams,
        ];

        return $this->app->authorizeUrl . (str_contains($this->app->authorizeUrl, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Completes the connect flow: trades the code for tokens and keeps them with $callbackExtras (what the
     * vendor's redirect named alongside the code, such as QuickBooks' company id).
     *
     * @param array<string, string> $callbackExtras
     */
    public function exchange(string $code, string $verifier, string $redirectUri, array $callbackExtras = []): TokenSet
    {
        $form = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri];
        if ($this->app->pkce) {
            $form['code_verifier'] = $verifier;
        }

        $kept = $this->app->refreshNamesRedirectUri ? [self::REDIRECT_URI_EXTRA => $redirectUri] : [];

        return $this->request($form, null, [...$callbackExtras, ...$kept]);
    }

    /** A current access token: the stored one, or a new one when it is about to expire. */
    public function current(): TokenSet
    {
        $tokens = $this->tokens->get() ?? throw new SourceError("{$this->app->label} is not connected: use \"Connect to {$this->app->label}\" on the settings page.");

        return $tokens->isExpired($this->clock->now(), self::EXPIRY_MARGIN_SECONDS) ? $this->refresh() : $tokens;
    }

    /** A new access token from the stored refresh token. */
    public function refresh(): TokenSet
    {
        $tokens = $this->tokens->get() ?? throw new SourceError("{$this->app->label} is not connected: use \"Connect to {$this->app->label}\" on the settings page.");
        if ($tokens->refreshToken === null) {
            throw new CredentialsRejected("{$this->app->label} rejected the access token and issued no refresh token; connect the account again.");
        }

        $form = ['grant_type' => 'refresh_token', 'refresh_token' => $tokens->refreshToken];
        $redirectUri = $tokens->extra(self::REDIRECT_URI_EXTRA);
        if ($this->app->refreshNamesRedirectUri && $redirectUri !== null) {
            $form['redirect_uri'] = $redirectUri;
        }

        return $this->request($form, $tokens, []);
    }

    public function isConnected(): bool
    {
        return $this->tokens->get() !== null;
    }

    /**
     * Posts a token request and saves the answer; a refresh response that leaves out the refresh token or kept
     * fields keeps the previous ones.
     *
     * @param array<string, string> $form
     * @param array<string, string> $callbackExtras
     */
    private function request(array $form, ?TokenSet $previous, array $callbackExtras): TokenSet
    {
        $json = $this->app->clientAuth === ClientAuth::Json;
        $headers = ['Content-Type' => $json ? 'application/json' : 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        if ($this->app->clientAuth === ClientAuth::Basic) {
            $headers['Authorization'] = 'Basic ' . base64_encode("{$this->app->clientId}:{$this->app->clientSecret}");
        } else {
            $form += ['client_id' => $this->app->clientId, 'client_secret' => $this->app->clientSecret];
        }
        $body = $json ? (string) json_encode($form, JSON_UNESCAPED_SLASHES) : http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $response = $this->transport->post($this->app->tokenUrl, $headers, $body, self::REQUEST_TIMEOUT_SECONDS);
        if ($response->status === self::HTTP_BAD_REQUEST || $response->status === self::HTTP_UNAUTHORIZED) {
            throw new CredentialsRejected("{$this->app->label} refused the token request (HTTP {$response->status}); connect the account again.");
        }
        if ($response->status !== self::HTTP_OK) {
            throw new SourceError("{$this->app->label}'s token request failed (HTTP {$response->status}).");
        }
        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SourceError("{$this->app->label} returned an unreadable token response.");
        }
        $access = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;
        if (!is_array($decoded) || !is_string($access) || $access === '') {
            throw new SourceError("{$this->app->label} returned a token response without an access token.");
        }
        $refresh = $decoded['refresh_token'] ?? null;
        $expiresIn = $decoded['expires_in'] ?? null;
        $extras = [...($previous === null ? [] : $previous->extras), ...$callbackExtras];
        foreach ($this->keptFields as $field) {
            if (is_string($decoded[$field] ?? null) && $decoded[$field] !== '') {
                $extras[$field] = $decoded[$field];
            }
        }
        $tokens = new TokenSet(
            $access,
            is_string($refresh) && $refresh !== '' ? $refresh : $previous?->refreshToken,
            is_numeric($expiresIn) ? (int) $this->clock->now() + (int) $expiresIn : null,
            $extras,
        );
        $this->tokens->save($tokens);

        return $tokens;
    }
}
