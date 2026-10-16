<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\RetryPolicy;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\RetryLater;
use IdempotentImporter\Source\SourceError;

/**
 * A Dev Dashboard app installed on the store: its client ID and secret are exchanged for an Admin API token
 * (the client credentials grant; the token lasts 24 hours) on first use, and again whenever Shopify rejects
 * the token. Shopify grants this only to apps of the store's own organization
 * (shopify.dev/docs/apps/build/dev-dashboard/create-apps-using-dev-dashboard). A refused grant (a wrong
 * secret, the app not installed) is CredentialsRejected, so the run stops instead of retrying; a busy or
 * failing token endpoint is RetryLater.
 */
final class ClientCredentialsAuthorizer implements RequestAuthorizer
{
    public const REQUEST_TIMEOUT_SECONDS = 30;

    private const GRANT_TYPE = 'client_credentials';
    private const HTTP_OK = 200;
    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_SERVER_ERROR = 500;
    private const FIRST_ATTEMPT = 1;
    /** Shopify's OAuth error codes are snake_case words (e.g. app_not_installed); anything else isn't echoed. */
    private const ERROR_CODE_PATTERN = '/^[a-z_]{1,64}$/';

    private ?string $accessToken = null;

    public function __construct(
        private readonly string $tokenUrl,
        #[\SensitiveParameter]
        private readonly string $clientId,
        #[\SensitiveParameter]
        private readonly string $clientSecret,
        private readonly HttpTransport $transport,
    ) {
        if ($clientId === '' || $clientSecret === '') {
            throw new SourceError("Shopify is not configured: enter the app's client ID and client secret.");
        }
    }

    public function headers(): array
    {
        return [ShopifyTokenAuthorizer::HEADER => $this->accessToken ??= $this->grant()];
    }

    public function renew(): bool
    {
        $this->accessToken = null;

        return true;
    }

    private function grant(): string
    {
        $response = $this->transport->post(
            $this->tokenUrl,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query(['grant_type' => self::GRANT_TYPE, 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret]),
            self::REQUEST_TIMEOUT_SECONDS,
        );
        if ($response->status === self::HTTP_TOO_MANY_REQUESTS || $response->status >= self::HTTP_SERVER_ERROR) {
            throw new RetryLater(
                RetryPolicy::wait($response, self::FIRST_ATTEMPT) ?? RetryPolicy::backoff(self::FIRST_ATTEMPT),
                "Shopify could not issue an access token (HTTP {$response->status}).",
            );
        }
        $decoded = json_decode($response->body, true);
        $body = is_array($decoded) ? $decoded : [];
        if ($response->status !== self::HTTP_OK) {
            $code = is_string($body['error'] ?? null) && preg_match(self::ERROR_CODE_PATTERN, $body['error']) === 1 ? ": {$body['error']}" : '';

            throw new CredentialsRejected(
                "Shopify refused to issue an access token (HTTP {$response->status}{$code}). Check the app's client ID and secret, and that the app is installed on the store.",
            );
        }
        $token = $body['access_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : throw new SourceError('Shopify issued no access token.');
    }
}
