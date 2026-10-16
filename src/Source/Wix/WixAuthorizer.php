<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix;

use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\SourceError;

/**
 * Wix requests with an account API key: the key itself in `Authorization`, and the site it reads in
 * `wix-site-id` (an API key isn't bound to one site). The key lives until it is revoked, so there is
 * nothing to renew.
 */
final readonly class WixAuthorizer implements RequestAuthorizer
{
    public const SITE_HEADER = 'wix-site-id';

    public function __construct(#[\SensitiveParameter] private string $apiKey, private string $siteId)
    {
        if ($apiKey === '') {
            throw new SourceError('Wix is not configured: enter the API key.');
        }
        if ($siteId === '') {
            throw new SourceError('Wix is not configured: enter the site id.');
        }
    }

    public function headers(): array
    {
        return ['Authorization' => $this->apiKey, self::SITE_HEADER => $this->siteId];
    }

    public function renew(): bool
    {
        return false;
    }
}
