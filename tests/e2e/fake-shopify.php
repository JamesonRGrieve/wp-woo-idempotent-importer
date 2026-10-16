<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * A stand-in for Shopify for the end-to-end suite, run with `php -S`. GraphQL POSTs are answered from the
 * store state named in the STATE_FILE (v1, v2, ...) through the same FixtureResponder the unit tests use;
 * CDN paths return small generated files of the right type. Requests must carry the test access token.
 */

use IdempotentShopifyImporter\Tests\Support\FixtureResponder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const STATE_FILE = '/state/store-state';
const ACCESS_TOKEN = 'e2e-token';
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const PDF_MINIMAL = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (($_SERVER['HTTP_X_SHOPIFY_ACCESS_TOKEN'] ?? '') !== ACCESS_TOKEN) {
        http_response_code(401);

        return;
    }
    $state = trim((string) file_get_contents(STATE_FILE));
    $request = json_decode((string) file_get_contents('php://input'), true);
    header('Content-Type: application/json');
    echo json_encode((new FixtureResponder($state))->respond(is_array($request) ? $request : []), JSON_THROW_ON_ERROR);

    return;
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
[$type, $body] = match ($extension) {
    'png' => ['image/png', (string) base64_decode(PNG_1X1, true)],
    'pdf' => ['application/pdf', PDF_MINIMAL],
    default => [null, null],
};
if ($type === null) {
    http_response_code(404);

    return;
}
header('Content-Type: ' . $type);
echo $body;
