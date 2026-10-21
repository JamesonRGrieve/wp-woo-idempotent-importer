<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

use IdempotentImporter\Model\Kind;

/** One object a source's webhook says changed (or was deleted): it is re-read through the same sync as a run. */
final readonly class WebhookEvent
{
    public function __construct(
        public Kind $kind,
        public string $gid,
        public bool $deletion,
    ) {}
}
