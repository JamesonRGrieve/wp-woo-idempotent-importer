<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive;

use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\SourceError;

/**
 * Pipedrive requests with a user's personal API token, in the `x-api-token` header (the `api_key` security
 * scheme of Pipedrive's published documents). The token doesn't expire, so there is nothing to renew.
 */
final readonly class ApiTokenAuthorizer implements RequestAuthorizer
{
    public const HEADER = 'x-api-token';

    public function __construct(#[\SensitiveParameter] private string $token)
    {
        if ($token === '') {
            throw new SourceError('Pipedrive is not configured: enter an API token or connect the company.');
        }
    }

    public function headers(): array
    {
        return [self::HEADER => $this->token];
    }

    public function renew(): bool
    {
        return false;
    }
}
