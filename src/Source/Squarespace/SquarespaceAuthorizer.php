<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Squarespace;

use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\SourceError;

/**
 * Squarespace requests as the site: an API key (or an OAuth access token) as a bearer token, and the
 * User-Agent naming this app, which Squarespace requires on every request (it rejects requests without one,
 * and limits generic client agents more strictly). The key is static: there is nothing to renew.
 */
final readonly class SquarespaceAuthorizer implements RequestAuthorizer
{
    public const USER_AGENT = 'wp-woo-idempotent-importer';

    public function __construct(#[\SensitiveParameter] private string $apiKey)
    {
        if ($apiKey === '') {
            throw new SourceError('Squarespace is not configured: enter the API key.');
        }
    }

    public function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey, 'User-Agent' => self::USER_AGENT];
    }

    public function renew(): bool
    {
        return false;
    }
}
