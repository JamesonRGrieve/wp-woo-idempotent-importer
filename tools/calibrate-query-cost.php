<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Calibrates tools/QueryCost.php against a real store: sends every read operation at its default page sizes
 * with Shopify-GraphQL-Cost-Debug and records Shopify's requested cost (from the cost refusal when it is over
 * the limit), plus the connection size multiplier for a range of page sizes, in
 * tests/fixtures/shopify-query-cost.json. Requested cost doesn't depend on the store's data, so placeholder ids
 * do. Read-only: mutations are skipped. The app's credentials come from the environment (SHOPIFY_STORE,
 * SHOPIFY_CLIENT_ID, SHOPIFY_CLIENT_SECRET) and are never printed.
 */

use IdempotentImporter\Source\Shopify\ApiVersion;
use IdempotentImporter\Source\Shopify\QueryLibrary;
use IdempotentImporter\Tools\CostCalibration;
use IdempotentImporter\Tools\Json;
use IdempotentImporter\Tools\QueryCost;
use IdempotentImporter\Tools\SchemaCache;

require __DIR__ . '/../vendor/autoload.php';

const PLACEHOLDER_ID = 'gid://shopify/Product/1';
const MULTIPLIER_SIZES = [1, 2, 3, 5, 8, 12, 13, 20, 21, 33, 34, 50, 90, 91, 100, 150, 249, 250];
const REQUEST_TIMEOUT_SECONDS = 60;
const PAUSE_MICROSECONDS = 250_000;

/** @return array<string, mixed> */
function post(string $url, string $contentType, string $body, string $token = ''): array
{
    $headers = "Content-Type: {$contentType}\r\nAccept: application/json\r\nShopify-GraphQL-Cost-Debug: 1\r\n"
        . ($token !== '' ? "X-Shopify-Access-Token: {$token}\r\n" : '');
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => $headers, 'content' => $body, 'timeout' => REQUEST_TIMEOUT_SECONDS, 'ignore_errors' => true,
    ]]);
    $response = file_get_contents($url, false, $context);
    if ($response === false) {
        throw new RuntimeException('No response from ' . parse_url($url, PHP_URL_HOST));
    }

    return Json::object(json_decode($response, true, 512, JSON_THROW_ON_ERROR), 'The response');
}

/** @param array<string, mixed> $response */
function requestedCost(array $response): int
{
    $cost = Json::object($response['extensions'] ?? [], 'extensions')['cost'] ?? null;
    if (is_array($cost) && is_int($cost['requestedQueryCost'] ?? null)) {
        return $cost['requestedQueryCost'];
    }
    foreach (is_array($response['errors'] ?? null) ? $response['errors'] : [] as $error) {
        if (is_array($error) && is_array($error['extensions'] ?? null) && is_int($error['extensions']['cost'] ?? null)) {
            return $error['extensions']['cost'];
        }
    }

    throw new RuntimeException('Shopify reported no cost.');
}

$store = (string) getenv('SHOPIFY_STORE');
$clientId = (string) getenv('SHOPIFY_CLIENT_ID');
$clientSecret = (string) getenv('SHOPIFY_CLIENT_SECRET');
if ($store === '' || $clientId === '' || $clientSecret === '') {
    fwrite(STDERR, "Set SHOPIFY_STORE, SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET in the environment.\n");
    exit(1);
}
$grant = post("https://{$store}/admin/oauth/access_token", 'application/x-www-form-urlencoded', http_build_query([
    'grant_type' => 'client_credentials', 'client_id' => $clientId, 'client_secret' => $clientSecret,
]));
$token = is_string($grant['access_token'] ?? null) ? $grant['access_token'] : '';
if ($token === '') {
    fwrite(STDERR, "Shopify issued no access token.\n");
    exit(1);
}
$endpoint = "https://{$store}/admin/api/" . ApiVersion::PINNED . '/graphql.json';
$graphql = static function (string $query, array $variables = [], ?string $operation = null) use ($endpoint, $token): int {
    usleep(PAUSE_MICROSECONDS);

    return requestedCost(post($endpoint, 'application/json', json_encode(
        ['query' => $query, 'variables' => (object) $variables, 'operationName' => $operation],
        JSON_THROW_ON_ERROR,
    ), $token));
};

$library = QueryLibrary::bundled();
$estimator = new QueryCost(SchemaCache::load(ApiVersion::PINNED));
$operations = [];
foreach ($library->operations() as $operation) {
    $document = $library->document($operation);
    if (str_starts_with(CostCalibration::stripComments($document), 'mutation')) {
        continue;
    }
    $variables = CostCalibration::placeholders($document, PLACEHOLDER_ID) + $library->pageSizes($operation);
    $real = $graphql($document, $variables, $operation);
    $estimated = $estimator->of($document, $variables);
    $operations[$operation] = ['variables' => $variables, 'requestedQueryCost' => $real];
    fwrite(STDOUT, sprintf("%-32s estimated %5d  Shopify %5d%s\n", $operation, $estimated, $real, $estimated === $real ? '' : '  MISMATCH'));
}
$multipliers = [];
foreach (MULTIPLIER_SIZES as $size) {
    $multipliers[(string) $size] = $graphql("{ urlRedirects(first: {$size}) { nodes { id } } }") - CostCalibration::PROBE_FIXED_COST;
}
CostCalibration::write([
    'apiVersion' => ApiVersion::PINNED,
    'recorded' => gmdate('Y-m-d'),
    'connectionMultipliers' => $multipliers,
    'operations' => $operations,
]);
fwrite(STDOUT, 'Wrote ' . CostCalibration::path() . "\n");
