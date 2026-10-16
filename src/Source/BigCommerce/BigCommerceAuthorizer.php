<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce;

use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\SourceError;

/**
 * BigCommerce requests as a store-level API account: its access token in `X-Auth-Token`. The token is
 * static (it lives until the account is deleted), so there is nothing to renew.
 */
final readonly class BigCommerceAuthorizer implements RequestAuthorizer
{
    public const TOKEN_HEADER = 'X-Auth-Token';

    public function __construct(#[\SensitiveParameter] private string $accessToken)
    {
        if ($accessToken === '') {
            throw new SourceError('BigCommerce is not configured: enter the API account access token.');
        }
    }

    public function headers(): array
    {
        return [self::TOKEN_HEADER => $this->accessToken];
    }

    public function renew(): bool
    {
        return false;
    }
}
