<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * A stand-in for every built source for the end-to-end suite, run with `php -S` and reachable as
 * <source>-fake. Each source answers from the fixture state run.sh wrote to /state/<source>-state, through
 * the responders the unit tests use: Shopify GraphQL POSTs through the ShopifyResponder, Etsy (`/v3/…`)
 * through the EtsyResponder, and the REST sources in REST_SOURCES through the FixtureResponder. Requests
 * must carry the test credentials. Media paths return small generated files of the right type. While
 * TROUBLE_FILE exists, every API request is rate limited or refused instead (the lockout-safety checks).
 */

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Source\BigCommerce\BigCommerceAuthorizer;
use IdempotentImporter\Source\Ecwid\EcwidAuthorizer;
use IdempotentImporter\Source\Lightspeed\LightspeedAuthorizer;
use IdempotentImporter\Source\Magento\MagentoAuthorizer;
use IdempotentImporter\Source\Pipedrive\ApiTokenAuthorizer;
use IdempotentImporter\Source\SquareOnline\SquareOnlineAuthorizer;
use IdempotentImporter\Source\Squarespace\SquarespaceAuthorizer;
use IdempotentImporter\Source\Wix\WixAuthorizer;
use IdempotentImporter\Source\Zoho\ZohoAdapter;
use IdempotentImporter\Tests\Support\EtsyResponder;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\ShopifyResponder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** The token the fake Shopify issues for the e2e app's client credentials (a Dev Dashboard app's grant). */
const SHOPIFY_ACCESS_TOKEN = 'e2e-token';
const SHOPIFY_TOKEN_PATH = '/admin/oauth/access_token';
const SHOPIFY_CLIENT_GRANT = ['grant_type' => 'client_credentials', 'client_id' => 'e2e-client-id', 'client_secret' => 'e2e-client-secret'];
const ETSY_API_KEY = 'e2e-keystring:e2e-shared-secret';
const ETSY_API_PREFIX = '/v3/';
/** REST sources answered from their fixtures: source => [API path pattern, the headers every request must carry]. */
const REST_SOURCES = [
    'square_online' => ['#^/v2/#', ['Authorization' => 'Bearer e2e-square-token', 'Square-Version' => SquareOnlineAuthorizer::API_VERSION]],
    // The Square account gift cards are written to (whatever the source).
    'square_stored_value' => ['#^/v2/#', ['Authorization' => 'Bearer e2e-square-token', 'Square-Version' => SquareOnlineAuthorizer::API_VERSION]],
    'bigcommerce' => ['#^/stores/#', [BigCommerceAuthorizer::TOKEN_HEADER => 'e2e-bigcommerce-token']],
    'squarespace' => ['#^/(1\.0|v1|v2)/#', ['Authorization' => 'Bearer e2e-squarespace-key', 'User-Agent' => SquarespaceAuthorizer::USER_AGENT]],
    'wix' => [
        '#^/(stores|stores-reader|categories|site-properties|contacts|ecom|blog|seo-redirects-service|reviews|loyalty-accounts|loyalty-transactions)/#',
        ['Authorization' => 'e2e-wix-key', WixAuthorizer::SITE_HEADER => 'e2e-wix-site'],
    ],
    'magento' => ['#^/rest/[^/]+/V1/#', ['Authorization' => MagentoAuthorizer::BEARER . 'e2e-magento-token']],
    // HTTP Basic with e2e-lightspeed-key:e2e-lightspeed-secret, under the shop language's path.
    'lightspeed' => ['#^/[a-z]{2}/#', [LightspeedAuthorizer::HEADER => 'Basic ZTJlLWxpZ2h0c3BlZWQta2V5OmUyZS1saWdodHNwZWVkLXNlY3JldA==']],
    'ecwid' => ['#^/api/v3/\d+/#', [EcwidAuthorizer::HEADER => EcwidAuthorizer::BEARER . 'e2e-ecwid-token']],
    'webflow' => ['#^/v2/#', ['Authorization' => 'Bearer e2e-webflow-token']],
    'hubspot' => ['#^/crm/v3/#', ['Authorization' => 'Bearer e2e-hubspot-token']],
    'salesforce' => ['#^/services/data/#', ['Authorization' => 'Bearer e2e-salesforce-token']],
    'pipedrive' => ['#^/api/v[12]/#', [ApiTokenAuthorizer::HEADER => 'e2e-pipedrive-token']],
    'zoho' => ['#^/crm/v8/#', ['Authorization' => ZohoAdapter::SCHEME . ' e2e-zoho-token']],
    'freshbooks' => ['#^/(auth/api|accounting|comments)/#', ['Authorization' => 'Bearer e2e-freshbooks-token']],
    'wave' => ['#^/graphql/public$#', ['Authorization' => 'Bearer e2e-wave-token']],
    'quickbooks_online' => ['#^/v3/company/\d+/#', ['Authorization' => 'Bearer e2e-quickbooks-token']],
];
const FAKE_HOST_SUFFIX = '-fake';
/** Written by the lockout checks (assert.php): `throttle` or `reject`; absent normally. */
const TROUBLE_FILE = '/state/trouble';
const TROUBLE_THROTTLE = 'throttle';
const THROTTLE_RETRY_AFTER_SECONDS = 120;
const MEDIA_EXTENSIONS = ['png', 'pdf'];
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

// The lockout-safety checks switch every source into trouble: rate limited for longer than a request
// may wait, or refusing the credentials. Media keeps being served.
$trouble = is_file(TROUBLE_FILE) ? trim((string) file_get_contents(TROUBLE_FILE)) : '';
if ($trouble !== '' && !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), MEDIA_EXTENSIONS, true)) {
    if ($trouble === TROUBLE_THROTTLE) {
        header('Retry-After: ' . THROTTLE_RETRY_AFTER_SECONDS);
    }
    http_response_code($trouble === TROUBLE_THROTTLE ? 429 : 401);

    return;
}

if (isset(REST_SOURCES[$source]) && preg_match(REST_SOURCES[$source][0], $path) === 1) {
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

if ($method === 'POST' && $path === SHOPIFY_TOKEN_PATH) {
    parse_str(requestBody(), $grant);
    if ($grant !== SHOPIFY_CLIENT_GRANT) {
        http_response_code(400);
        echo '{"error":"invalid_client"}';

        return;
    }
    header('Content-Type: application/json');
    echo json_encode(['access_token' => SHOPIFY_ACCESS_TOKEN, 'scope' => 'write_products', 'expires_in' => 86399], JSON_THROW_ON_ERROR);

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
