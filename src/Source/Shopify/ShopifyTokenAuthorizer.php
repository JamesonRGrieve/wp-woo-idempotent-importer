<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\SourceError;

/**
 * A legacy custom app's permanent Admin API access token (created in the store admin's "Develop apps",
 * which Shopify closed to new apps on 2026-01-01). Nothing to renew: a rejected token stops the run.
 */
final class ShopifyTokenAuthorizer implements RequestAuthorizer
{
    public const HEADER = 'X-Shopify-Access-Token';

    public function __construct(#[\SensitiveParameter] private readonly string $accessToken)
    {
        if ($accessToken === '') {
            throw new SourceError('No Shopify access token is configured.');
        }
    }

    public function headers(): array
    {
        return [self::HEADER => $this->accessToken];
    }

    public function renew(): bool
    {
        return false;
    }
}
