<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed;

use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\SourceError;

/**
 * Lightspeed eCom requests as an API key: HTTP Basic authentication with the key as the user and its secret
 * as the password (developers.lightspeedhq.com/ecom/introduction/authentication/). The key is static (it
 * lives until it is deleted in the back office), so there is nothing to renew.
 */
final readonly class LightspeedAuthorizer implements RequestAuthorizer
{
    public const HEADER = 'Authorization';

    private string $credentials;

    public function __construct(#[\SensitiveParameter] string $apiKey, #[\SensitiveParameter] string $apiSecret)
    {
        if ($apiKey === '' || $apiSecret === '') {
            throw new SourceError('Lightspeed eCom is not configured: enter the API key and its secret.');
        }
        $this->credentials = base64_encode("{$apiKey}:{$apiSecret}");
    }

    public function headers(): array
    {
        return [self::HEADER => "Basic {$this->credentials}"];
    }

    public function renew(): bool
    {
        return false;
    }
}
