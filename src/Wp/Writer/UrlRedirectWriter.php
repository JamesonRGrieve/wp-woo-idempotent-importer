<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;

/**
 * Shopify URL redirects have no WordPress object: the redirect row itself is written (and removed on
 * retirement) by ObjectSync from the desired state's path and target, like every other redirect.
 */
final class UrlRedirectWriter implements Writer
{
    private const NO_OBJECT = 0;

    public function kind(): Kind
    {
        return Kind::UrlRedirect;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        return self::NO_OBJECT;
    }

    public function exists(int $targetId): bool
    {
        return true;
    }

    public function retire(Link $link): void
    {
        // Nothing beyond the redirect row, which ObjectSync removes.
    }
}
