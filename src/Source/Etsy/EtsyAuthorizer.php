<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy;

use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\SourceError;

/**
 * Etsy requests as the connected shop owner: the app's API key on every request and a bearer token,
 * refreshed on first use and again whenever Etsy rejects it.
 */
final class EtsyAuthorizer implements RequestAuthorizer
{
    /** Etsy access tokens are `<user id>.<token>`. */
    private const TOKEN_USER_SEPARATOR = '.';

    private ?string $accessToken = null;

    public function __construct(private readonly EtsyConnection $connection, private readonly OAuth $oauth) {}

    public function headers(): array
    {
        return ['x-api-key' => $this->connection->apiKey(), 'Authorization' => 'Bearer ' . $this->accessToken()];
    }

    public function renew(): bool
    {
        $this->accessToken = null;

        return true;
    }

    /** The connected shop owner's Etsy user id, from the access token. */
    public function userId(): int
    {
        $prefix = strstr($this->accessToken(), self::TOKEN_USER_SEPARATOR, true);
        if ($prefix === false || !ctype_digit($prefix)) {
            throw new SourceError('Etsy returned an access token without a user id.');
        }

        return (int) $prefix;
    }

    private function accessToken(): string
    {
        return $this->accessToken ??= $this->oauth->refresh();
    }
}
