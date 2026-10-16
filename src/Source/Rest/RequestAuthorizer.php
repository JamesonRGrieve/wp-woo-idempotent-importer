<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Rest;

/** How one source authenticates its API requests. */
interface RequestAuthorizer
{
    /**
     * The headers that authenticate a request (fetching or refreshing a token first if needed).
     *
     * @return array<string, string>
     */
    public function headers(): array;

    /** Forget any cached token, so the next headers() fetches a fresh one; false when there is none to renew. */
    public function renew(): bool;
}
