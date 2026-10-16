<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\SquareOnline;

use IdempotentImporter\Source\Rest\RequestAuthorizer;
use IdempotentImporter\Source\SourceError;

/**
 * Square requests as the seller: a personal access token (or an OAuth access token the seller issued) as a
 * bearer token, and the Square-Version the reader was written against, so Square answers in that version's
 * shapes. The token is static: there is nothing to renew.
 */
final readonly class SquareOnlineAuthorizer implements RequestAuthorizer
{
    /** The Square API version this adapter reads (the pinned spec in tools/SpecCache.php). */
    public const API_VERSION = '2026-09-16';

    public function __construct(#[\SensitiveParameter] private string $accessToken)
    {
        if ($accessToken === '') {
            throw new SourceError('Square Online is not configured: enter the access token.');
        }
    }

    public function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->accessToken, 'Square-Version' => self::API_VERSION];
    }

    public function renew(): bool
    {
        return false;
    }
}
