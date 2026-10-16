<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Shopify;

/** The one outbound HTTP boundary. Implementations bound every request with the given timeout. */
interface HttpTransport
{
    /** @param array<string, string> $headers */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse;

    /** Downloads a URL to a local temporary file and returns its path; the caller deletes it. */
    public function download(string $url, int $timeoutSeconds, int $maxBytes): string;
}
