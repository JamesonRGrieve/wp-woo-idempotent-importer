<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * A stand-in for Shopify, Etsy, Square and BigCommerce for the end-to-end suite, run with `php -S`
 * (reachable as shopify-fake, etsy-fake, square_online-fake and bigcommerce-fake). Shopify GraphQL POSTs are
 * answered from the state in SHOPIFY_STATE_FILE through the ShopifyResponder, Etsy API requests (`/v3/…`)
 * from the state in ETSY_STATE_FILE through the EtsyResponder, Square API requests (`/v2/…` to the
 * square_online-fake host) from the state in SQUARE_STATE_FILE and BigCommerce API requests (`/stores/…` to
 * the bigcommerce-fake host) from the state in BIGCOMMERCE_STATE_FILE through the FixtureResponder — the same
 * responders the unit tests use. Requests must carry the test credentials. Media paths return small
 * generated files of the right type.
 */

use IdempotentImporter\Source\BigCommerce\BigCommerceAuthorizer;
use IdempotentImporter\Source\SquareOnline\SquareOnlineAuthorizer;
use IdempotentImporter\Tests\Support\EtsyResponder;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\ShopifyResponder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const SHOPIFY_STATE_FILE = '/state/store-state';
const ETSY_STATE_FILE = '/state/etsy-state';
const SQUARE_STATE_FILE = '/state/square-state';
const SHOPIFY_ACCESS_TOKEN = 'e2e-token';
const ETSY_API_KEY = 'e2e-keystring:e2e-shared-secret';
const ETSY_API_PREFIX = '/v3/';
const SQUARE_HOST = 'square_online-fake';
const SQUARE_ACCESS_TOKEN = 'e2e-square-token';
const SQUARE_API_PREFIX = '/v2/';
const BIGCOMMERCE_STATE_FILE = '/state/bigcommerce-state';
const BIGCOMMERCE_HOST = 'bigcommerce-fake';
const BIGCOMMERCE_ACCESS_TOKEN = 'e2e-bigcommerce-token';
const BIGCOMMERCE_API_PREFIX = '/stores/';
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const PDF_MINIMAL = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = (string) parse_url($uri, PHP_URL_PATH);
$host = is_string($_SERVER['HTTP_HOST'] ?? null) ? (string) parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST) : '';

if ($host === SQUARE_HOST && str_starts_with($path, SQUARE_API_PREFIX)) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . SQUARE_ACCESS_TOKEN
        || ($_SERVER['HTTP_SQUARE_VERSION'] ?? '') !== SquareOnlineAuthorizer::API_VERSION) {
        http_response_code(401);

        return;
    }
    $response = (new FixtureResponder('square_online', trim((string) file_get_contents(SQUARE_STATE_FILE))))
        ->respond($method, 'http://' . SQUARE_HOST . $uri, (string) file_get_contents('php://input'));
    http_response_code($response->status);
    header('Content-Type: application/json');
    echo $response->body;

    return;
}

if ($host === BIGCOMMERCE_HOST && str_starts_with($path, BIGCOMMERCE_API_PREFIX)) {
    $header = 'HTTP_' . strtoupper(str_replace('-', '_', BigCommerceAuthorizer::TOKEN_HEADER));
    if (($_SERVER[$header] ?? '') !== BIGCOMMERCE_ACCESS_TOKEN) {
        http_response_code(401);

        return;
    }
    $response = (new FixtureResponder('bigcommerce', trim((string) file_get_contents(BIGCOMMERCE_STATE_FILE))))
        ->respond($method, 'http://' . BIGCOMMERCE_HOST . $uri, (string) file_get_contents('php://input'));
    http_response_code($response->status);
    header('Content-Type: application/json');
    echo $response->body;

    return;
}

if (str_starts_with($path, ETSY_API_PREFIX)) {
    $isToken = $path === EtsyResponder::TOKEN_PATH;
    $authorised = $isToken || (($_SERVER['HTTP_X_API_KEY'] ?? '') === ETSY_API_KEY
        && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer ' . EtsyResponder::ACCESS_TOKEN);
    if (!$authorised) {
        http_response_code(401);

        return;
    }
    $response = (new EtsyResponder(trim((string) file_get_contents(ETSY_STATE_FILE))))
        ->respond($method, 'http://etsy-fake' . $uri, (string) file_get_contents('php://input'));
    http_response_code($response->status);
    header('Content-Type: application/json');
    echo $response->body;

    return;
}

if ($method === 'POST') {
    if (($_SERVER['HTTP_X_SHOPIFY_ACCESS_TOKEN'] ?? '') !== SHOPIFY_ACCESS_TOKEN) {
        http_response_code(401);

        return;
    }
    $state = trim((string) file_get_contents(SHOPIFY_STATE_FILE));
    $request = json_decode((string) file_get_contents('php://input'), true);
    header('Content-Type: application/json');
    echo json_encode((new ShopifyResponder($state))->answer(is_array($request) ? $request : []), JSON_THROW_ON_ERROR);

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
