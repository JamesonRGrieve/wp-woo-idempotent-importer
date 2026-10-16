<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * End-to-end checks, run inside WordPress as `wp iim-e2e <scenario> [args]` (registered by the e2e
 * mu-plugin). Each scenario throws on the first failed expectation (wp exits non-zero) and prints what
 * it verified. A source's own checks live in checks/<source>.php.
 */

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Config\Settings;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Plugin;
use IdempotentImporter\Services;
use IdempotentImporter\Source\Adapters;
use IdempotentImporter\Source\Shopify\ShopifyAdapter;
use IdempotentImporter\Store\RunRecord;
use IdempotentImporter\Wp\Options;
use IdempotentImporter\Wp\Scheduler;
use IdempotentImporter\Wp\WebhookController;

/** WordPress + WooCommerce tables holding site content; the plugin's own bookkeeping is excluded. */
const CONTENT_TABLES = [
    'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'usermeta',
    'comments', 'commentmeta', 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data',
    'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_product_meta_lookup', 'wc_product_attributes_lookup',
    'woocommerce_shipping_zones', 'woocommerce_shipping_zone_locations', 'woocommerce_shipping_zone_methods',
    'woocommerce_attribute_taxonomies',
];
const WEBHOOK_SECRET = 'e2e-webhook-secret';
/** The sources in the order run.sh imports them, each beside the mirrors of the ones before it. */
const SOURCES = ['shopify', 'etsy', 'square_online', 'bigcommerce', 'squarespace'];
const FIRST_STATE = 'v1';

function expect(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException("FAILED: {$what}");
    }
    WP_CLI::log("ok - {$what}");
}

function services(): Services
{
    return new Services(Plugin::db());
}

function target(Kind $kind, string $gid): int
{
    $id = services()->links()->targetId($kind, $gid);
    expect($id !== null, "{$gid} is linked");

    return (int) $id;
}

function product(string $gid): WC_Product
{
    $product = wc_get_product(target(Kind::Product, $gid));

    return $product instanceof WC_Product ? $product : throw new RuntimeException("FAILED: {$gid} is not a product");
}

function order(Kind $kind, string $gid): WC_Order
{
    $order = wc_get_order(target($kind, $gid));

    return $order instanceof WC_Order ? $order : throw new RuntimeException("FAILED: {$gid} is not an order");
}

function user(string $gid): WP_User
{
    $user = get_user_by('id', target(Kind::Customer, $gid));

    return $user instanceof WP_User ? $user : throw new RuntimeException("FAILED: {$gid} is not a user");
}

function money(float|string $amount): string
{
    return wc_format_decimal((string) $amount, 2);
}

/** @return list<WP_Comment> a product's approved reviews */
function reviews(int $productId): array
{
    $comments = get_comments(['post_id' => $productId, 'type' => 'review', 'status' => 'approve']);

    return is_array($comments) ? array_values(array_filter($comments, static fn(mixed $comment): bool => $comment instanceof WP_Comment)) : [];
}

function latestRun(): RunRecord
{
    return services()->runLog()->recent(1)[0] ?? throw new RuntimeException('FAILED: no run recorded');
}

function checksum(): string
{
    $db = Plugin::db();
    $sums = [];
    foreach (CONTENT_TABLES as $table) {
        $row = $db->get_row($db->prepare('CHECKSUM TABLE %i', $db->prefix . $table), ARRAY_A);
        $sums[$table] = is_array($row) && is_scalar($row['Checksum'] ?? null) ? (string) $row['Checksum'] : 'missing';
    }

    return (string) json_encode($sums);
}

/** Selects a source, as if the operator had chosen it and saved; its credentials come from wp-config. */
function selectSource(string $source): void
{
    $settings = Options::array(Settings::OPTION);
    $settings['source'] = $source;
    update_option(Settings::OPTION, $settings, false);
}

/**
 * One of a source's checks from checks/<source>.php, which returns them by name: `configure` (optional; the
 * default only selects the source), `v1` and `v2` (what each state of the fake store must have produced) and
 * `intact` (its mirror is still there after later sources' runs).
 */
function check(string $source, string $name): ?Closure
{
    $checks = require __DIR__ . "/checks/{$source}.php";
    $check = is_array($checks) ? ($checks[$name] ?? null) : null;

    return $check instanceof Closure ? $check : null;
}

function requiredCheck(string $source, string $name): Closure
{
    return check($source, $name) ?? throw new RuntimeException("checks/{$source}.php has no {$name} check");
}

function configure(string $source): void
{
    (check($source, 'configure') ?? static fn() => selectSource($source))();
    WP_CLI::log('configured for ' . Adapters::for($source)->label());
}

/**
 * The run that imported a state of the source's fake store: it completed, wrote what changed, produced what
 * the state's checks expect, and left every earlier source's mirror alone (a source only retires its own).
 */
function state(string $source, string $state): void
{
    $position = array_search($source, SOURCES, true);
    if ($position === false) {
        throw new RuntimeException("{$source} is not in SOURCES");
    }
    $label = Adapters::for($source)->label();
    $run = latestRun();
    expect($run->status === 'complete', "the {$label} {$state} run completed: " . str_replace("\n", '; ', $run->message));
    if ($state !== FIRST_STATE) {
        expect($run->counts->writes() > 0, "the {$label} {$state} run wrote the changes");
    }
    requiredCheck($source, $state)();
    foreach (array_slice(SOURCES, 0, $position) as $earlier) {
        requiredCheck($earlier, 'intact')();
    }
}

function noMail(): void
{
    expect(Options::int('iim_e2e_mail_sent') === 0, 'no email reached the mailer during the imports');
}

function zeroWrites(string $before): void
{
    $run = latestRun();
    expect($run->status === 'complete', 'the re-run completed');
    expect($run->counts->writes() === 0, 're-run reports zero writes: ' . str_replace("\n", '; ', $run->message));
    expect(checksum() === $before, 'every content table is byte-identical after the re-run');
}

function webhook(): void
{
    update_option(Credentials::OPTION, [ShopifyAdapter::WEBHOOK_SECRET => WEBHOOK_SECRET], false);
    $body = (string) json_encode(['id' => 301]);
    $request = new WP_REST_Request('POST', '/' . WebhookController::NAMESPACE . WebhookController::ROUTE);
    $request->set_body($body);
    $request->set_header('x-shopify-topic', 'products/update');
    $request->set_header('x-shopify-hmac-sha256', base64_encode(hash_hmac('sha256', $body, WEBHOOK_SECRET, true)));
    expect(rest_do_request($request)->get_status() === 200, 'signed webhook accepted');
    $forged = clone $request;
    $forged->set_header('x-shopify-hmac-sha256', base64_encode('forged'));
    expect(rest_do_request($forged)->get_status() === 401, 'forged webhook rejected');
    expect(as_has_scheduled_action(Scheduler::OBJECT_HOOK, ['product', 'gid://shopify/Product/301', false], Scheduler::GROUP), 'object sync queued');
}

/** @param array<array-key, mixed> $arguments */
function argument(array $arguments, int $index): string
{
    $value = $arguments[$index] ?? '';

    return is_string($value) ? $value : '';
}

/** @param array<array-key, mixed> $arguments */
function scenario(array $arguments): void
{
    $scenario = argument($arguments, 0);
    match ($scenario) {
        'configure' => configure(argument($arguments, 1)),
        'state' => state(argument($arguments, 1), argument($arguments, 2)),
        'checksum' => file_put_contents(argument($arguments, 1), checksum()),
        'zero-writes' => zeroWrites((string) file_get_contents(argument($arguments, 1))),
        'webhook' => webhook(),
        'no-mail' => noMail(),
        default => throw new RuntimeException("Unknown scenario {$scenario}"),
    };
}

WP_CLI::add_command('iim-e2e', scenario(...));
