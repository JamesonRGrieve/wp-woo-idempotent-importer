<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use IdempotentImporter\Http\HttpResponse;

/** Answers a recorded source API request from fixtures, the way that source's API would. */
interface Responder
{
    public function respond(string $method, string $url, string $body): HttpResponse;
}
