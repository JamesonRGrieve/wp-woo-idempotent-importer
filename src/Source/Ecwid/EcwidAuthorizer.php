<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Ecwid;

use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\SourceError;

/**
 * Ecwid requests as a custom app: the app's secret access token as a bearer token
 * (docs.ecwid.com/api-reference/rest-api/rest-api-overview, "Headers" of every endpoint page). A custom app's
 * secret token doesn't expire (it lives until the app is removed), so there is nothing to renew.
 */
final readonly class EcwidAuthorizer implements RequestAuthorizer
{
    public const HEADER = 'Authorization';
    public const BEARER = 'Bearer ';

    public function __construct(#[\SensitiveParameter] private string $secretToken)
    {
        if ($secretToken === '') {
            throw new SourceError('Ecwid is not configured: enter the secret access token of the custom app.');
        }
    }

    public function headers(): array
    {
        return [self::HEADER => self::BEARER . $this->secretToken];
    }

    public function renew(): bool
    {
        return false;
    }
}
