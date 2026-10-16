<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Magento;

use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\SourceError;

/**
 * Magento requests as an integration: its access token as a bearer token (Adobe Commerce web API, "Token-based
 * authentication": https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-token).
 * Since 2.4.4 a store accepts an integration's access token on its own only once "Allow OAuth Access Tokens to
 * be used as standalone Bearer tokens" is enabled (oauth/consumer/enable_integration_as_bearer). The token
 * lives until the integration is reauthorized or deleted, so there is nothing to renew.
 */
final readonly class MagentoAuthorizer implements RequestAuthorizer
{
    public const BEARER = 'Bearer ';

    public function __construct(#[\SensitiveParameter] private string $accessToken)
    {
        if ($accessToken === '') {
            throw new SourceError('Magento is not configured: enter the integration access token.');
        }
    }

    public function headers(): array
    {
        return ['Authorization' => self::BEARER . $this->accessToken];
    }

    public function renew(): bool
    {
        return false;
    }
}
