<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;

/**
 * A source platform that is planned but not built: it is listed (so the choice and its coverage page
 * exist), reads nothing and maps nothing, and selecting it fails with a clear message rather than
 * silently importing nothing.
 */
abstract class DeferredAdapter implements Adapter
{
    final public function isDeferred(): bool
    {
        return true;
    }

    final public function kinds(): array
    {
        return [];
    }

    final public function mappers(Settings $settings): array
    {
        return [];
    }

    final public function reader(Settings $settings, HttpTransport $transport): SourceReader
    {
        throw new SourceError("Importing from {$this->label()} is not built yet.");
    }

    final public function supportsWebhooks(): bool
    {
        return false;
    }

    final public function filterHint(): ?string
    {
        return null;
    }

    final public function credentialFields(): array
    {
        return [];
    }
}
