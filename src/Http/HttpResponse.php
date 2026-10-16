<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Http;

final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public ?string $retryAfter = null,
    ) {}
}
