<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * A stand-in for every built source for the end-to-end suite, run with `php -S` and reachable as
 * <source>-fake. Each source answers from the fixture state run.sh wrote to /state/<source>-state, through
 * the responders the unit tests use: Shopify GraphQL POSTs through the ShopifyResponder, Etsy (`/v3/…`)
 * through the EtsyResponder, and the REST sources in REST_SOURCES through the FixtureResponder. Requests
 * must carry the test credentials. Media paths return small generated files of the right type.
 */

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Source\BigCommerce\BigCommerceAuthorizer;
use IdempotentImporter\Source\SquareOnline\SquareOnlineAuthorizer;
use IdempotentImporter\Tests\Support\EtsyResponder;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\ShopifyResponder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const SHOPIFY_ACCESS_TOKEN = 'e2e-token';
const ETSY_API_KEY = 'e2e-keystring:e2e-shared-secret';
const ETSY_API_PREFIX = '/v3/';
/** REST sources answered from their fixtures: source => [API path prefix, the headers every request must carry]. */
const REST_SOURCES = [
    'square_online' => ['/v2/', ['Authorization' => 'Bearer e2e-square-token', 'Square-Version' => SquareOnlineAuthorizer::API_VERSION]],
    'bigcommerce' => ['/stores/', [BigCommerceAuthorizer::TOKEN_HEADER => 'e2e-bigcommerce-token']],
];
const FAKE_HOST_SUFFIX = '-fake';
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const PDF_MINIMAL = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

function requestHeader(string $name): string
{
    $value = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '';

    return is_string($value) ? $value : '';
}

function fixtureState(string $source): string
{
    return trim((string) file_get_contents("/state/{$source}-state"));
}

function requestBody(): string
{
    return (string) file_get_contents('php://input');
}

function sendJson(HttpResponse $response): void
{
    http_response_code($response->status);
    header('Content-Type: application/json');
    echo $response->body;
}

$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = (string) parse_url($uri, PHP_URL_PATH);
$host = is_string($_SERVER['HTTP_HOST'] ?? null) ? (string) parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST) : '';
$source = str_ends_with($host, FAKE_HOST_SUFFIX) ? substr($host, 0, -strlen(FAKE_HOST_SUFFIX)) : '';

if (isset(REST_SOURCES[$source]) && str_starts_with($path, REST_SOURCES[$source][0])) {
    foreach (REST_SOURCES[$source][1] as $name => $expected) {
        if (requestHeader($name) !== $expected) {
            http_response_code(401);

            return;
        }
    }
    sendJson((new FixtureResponder($source, fixtureState($source)))->respond($method, "http://{$host}{$uri}", requestBody()));

    return;
}

if (str_starts_with($path, ETSY_API_PREFIX)) {
    $authorised = $path === EtsyResponder::TOKEN_PATH
        || (requestHeader('X-Api-Key') === ETSY_API_KEY && requestHeader('Authorization') === 'Bearer ' . EtsyResponder::ACCESS_TOKEN);
    if (!$authorised) {
        http_response_code(401);

        return;
    }
    sendJson((new EtsyResponder(fixtureState('etsy')))->respond($method, 'http://etsy-fake' . $uri, requestBody()));

    return;
}

if ($method === 'POST') {
    if (requestHeader('X-Shopify-Access-Token') !== SHOPIFY_ACCESS_TOKEN) {
        http_response_code(401);

        return;
    }
    $request = json_decode(requestBody(), true);
    header('Content-Type: application/json');
    echo json_encode((new ShopifyResponder(fixtureState('shopify')))->answer(is_array($request) ? $request : []), JSON_THROW_ON_ERROR);

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
