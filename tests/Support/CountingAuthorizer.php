<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use IdempotentImporter\Source\Rest\RequestAuthorizer;

/** A test authorizer: a numbered token that changes on each renewal (when renewable), counting renewals. */
final class CountingAuthorizer implements RequestAuthorizer
{
    public const HEADER = 'X-Auth-Token';

    public int $renewals = 0;

    public function __construct(private readonly bool $renewable) {}

    public function headers(): array
    {
        return [self::HEADER => 'token-' . ($this->renewals + 1)];
    }

    public function renew(): bool
    {
        if ($this->renewable) {
            ++$this->renewals;
        }

        return $this->renewable;
    }
}
