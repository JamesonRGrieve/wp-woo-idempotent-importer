<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Shopify;

use IdempotentImporter\Model\Kind;

final readonly class WebhookEvent
{
    public function __construct(
        public Kind $kind,
        public string $gid,
        public bool $deletion,
    ) {}
}
