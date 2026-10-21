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
use IdempotentImporter\Source\SquareOnline\SquareOnlineAdapter;
use IdempotentImporter\Source\SquareOnline\SquareWebhooks;
use IdempotentImporter\Store\RunRecord;
use IdempotentImporter\Sync\Mode;
use IdempotentImporter\Sync\Runner;
use IdempotentImporter\Sync\RunState;
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
    // FluentCRM, the home of CRM data.
    'fc_subscribers', 'fc_subscriber_meta', 'fc_subscriber_pivot', 'fc_subscriber_notes', 'fc_lists', 'fc_tags', 'fc_companies',
    'fc_meta',
];
const WEBHOOK_SECRET = 'e2e-webhook-secret';
/** The Shopify app's client secret, as wp-config-extra.php defines it (Dev Dashboard apps sign webhooks with it). */
const SHOPIFY_CLIENT_SECRET = 'e2e-client-secret';
/** The signature key of the e2e Square webhook subscription. */
const SQUARE_SIGNATURE_KEY = 'e2e-square-signature-key';
/** The fake store's trouble switch (see fake-store.php): every API request rate limited or refused. */
const TROUBLE_FILE = '/state/trouble';
const TROUBLE_THROTTLE = 'throttle';
const TROUBLE_REJECT = 'reject';
const THROTTLE_RETRY_AFTER_SECONDS = 120;
const CLOCK_SLACK_SECONDS = 5;
const STEP_BUDGET_SECONDS = 60.0;
/** The sources in the order run.sh imports them, each beside the mirrors of the ones before it. */
const SOURCES = ['shopify', 'etsy', 'square_online', 'bigcommerce', 'squarespace', 'wix', 'magento', 'lightspeed', 'ecwid', 'webflow'];
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

function post(Kind $kind, string $gid): WP_Post
{
    $post = get_post(target($kind, $gid));

    return $post instanceof WP_Post ? $post : throw new RuntimeException("FAILED: {$gid} is not a post");
}

function term(Kind $kind, string $gid, string $taxonomy): WP_Term
{
    $term = get_term(target($kind, $gid), $taxonomy);

    return $term instanceof WP_Term ? $term : throw new RuntimeException("FAILED: {$gid} is not a {$taxonomy} term");
}

function variation(string $gid): WC_Product_Variation
{
    $variation = wc_get_product(target(Kind::Variant, $gid));

    return $variation instanceof WC_Product_Variation ? $variation : throw new RuntimeException("FAILED: {$gid} is not a variation");
}

function money(float|string $amount): string
{
    return wc_format_decimal((string) $amount, 2);
}

/** How many products are in the trash, across every source (get_posts would stop at its default page of 5). */
function trashedProducts(): int
{
    return count(get_posts(['post_type' => 'product', 'post_status' => 'trash', 'fields' => 'ids', 'numberposts' => -1]));
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

    update_option(Credentials::OPTION, [], false);
    $byApp = clone $request;
    $byApp->set_header('x-shopify-hmac-sha256', base64_encode(hash_hmac('sha256', $body, SHOPIFY_CLIENT_SECRET, true)));
    expect(rest_do_request($byApp)->get_status() === 200, "with no legacy signing secret, a webhook signed with the app's client secret is accepted");
    expect(rest_do_request($request)->get_status() === 401, 'and one signed with the old secret is not');
}

/**
 * Square's webhooks against real WordPress, with Square the active source: a notification signed with the
 * subscription's key over the endpoint URL and body is accepted and queues the order and its returns; a
 * forged one, or one signed for another URL, is refused.
 */
function squareWebhook(): void
{
    Credentials::save(SquareOnlineAdapter::WEBHOOK_SIGNATURE_KEY, SQUARE_SIGNATURE_KEY);
    $body = (string) json_encode(['merchant_id' => 'MERCHANT1', 'type' => 'refund.created', 'event_id' => 'EVT-E2E', 'data' => ['type' => 'refund', 'id' => 'PRF_4', 'object' => ['refund' => ['id' => 'PRF_4', 'order_id' => 'ORDER_4']]]]);
    $sign = static fn(string $url): string => base64_encode(hash_hmac('sha256', $url . $body, SQUARE_SIGNATURE_KEY, true));
    $request = new WP_REST_Request('POST', '/' . WebhookController::NAMESPACE . WebhookController::ROUTE);
    $request->set_body($body);
    $request->set_header(SquareWebhooks::SIGNATURE_HEADER, $sign(WebhookController::url()));
    expect(rest_do_request($request)->get_status() === 200, 'signed Square webhook accepted');
    foreach ([['order', 'Order'], ['return', 'Return']] as [$kind, $type]) {
        expect(as_has_scheduled_action(Scheduler::OBJECT_HOOK, [$kind, "gid://square_online/{$type}/ORDER_4", false], Scheduler::GROUP), "the refund's {$kind} is queued");
    }
    $otherUrl = clone $request;
    $otherUrl->set_header(SquareWebhooks::SIGNATURE_HEADER, $sign('https://elsewhere.example/webhook'));
    expect(rest_do_request($otherUrl)->get_status() === 401, 'a webhook signed for another URL is rejected');
    $forged = clone $request;
    $forged->set_header(SquareWebhooks::SIGNATURE_HEADER, base64_encode('forged'));
    expect(rest_do_request($forged)->get_status() === 401, 'forged Square webhook rejected');
    Credentials::save(SquareOnlineAdapter::WEBHOOK_SIGNATURE_KEY, '');
}

/** Runs the current run to its end, a step at a time. */
function finishRun(Runner $runner): void
{
    while (!$runner->step(STEP_BUDGET_SECONDS)) {
        expect(!$runner->isRunning() || $runner->resumeAt() === null, 'the run does not pause while the source is healthy');
    }
}

/**
 * Lockout safety against real WordPress, with the active source in trouble (the fake store's TROUBLE_FILE).
 * Rate limited for longer than a request may wait: the run pauses where it is (nothing failed, nothing
 * written, the lock kept) and finishes cleanly once the source recovers. Refusing the credentials: the run
 * stops at once, and scheduled runs hold off until a run is started by hand.
 */
function lockout(): void
{
    $runner = services()->runner();
    file_put_contents(TROUBLE_FILE, TROUBLE_THROTTLE);
    $run = $runner->start(Mode::Incremental, false) ?? throw new RuntimeException('FAILED: the run did not start');
    expect(!$runner->step(STEP_BUDGET_SECONDS), 'a source rate limiting for longer than a request may wait pauses the run');
    $paused = RunState::load() ?? throw new RuntimeException('FAILED: the paused run kept no state');
    $resumeAt = $paused->resumeAt ?? 0;
    expect($runner->resumeAt() === $resumeAt && $resumeAt - time() >= THROTTLE_RETRY_AFTER_SECONDS - CLOCK_SLACK_SECONDS, "the pause lasts at least the source's Retry-After");
    $kind = $paused->current() ?? throw new RuntimeException('FAILED: the paused run has no kind');
    expect($paused->index === 0 && $paused->cursor === null && !$paused->counts->isIncomplete($kind) && $paused->counts->writes() === 0, 'it pauses where it was, with nothing failed or written');
    expect(services()->lock()->holder() === $run->id, 'the paused run keeps the lock, so no other run starts meanwhile');
    expect(!$runner->step(STEP_BUDGET_SECONDS) && (RunState::load()->resumeAt ?? 0) === $resumeAt, 'a step during the pause sends nothing and leaves the pause as it was');

    unlink(TROUBLE_FILE);
    $paused->resumeAt = time() - 1;
    $paused->save();
    finishRun($runner);
    $latest = latestRun();
    expect($latest->id === $run->id && $latest->status === 'complete' && $latest->counts->writes() === 0, 'once the source recovers, the paused run finishes cleanly');

    file_put_contents(TROUBLE_FILE, TROUBLE_REJECT);
    $runner->start(Mode::Incremental, false) ?? throw new RuntimeException('FAILED: the run did not start');
    expect($runner->step(STEP_BUDGET_SECONDS), 'rejected credentials end the run at once');
    expect(latestRun()->status === 'incomplete', 'the stopped run is recorded as incomplete');
    $source = services()->settings()->source;
    expect(services()->credentialHold()->reason($source) !== null, 'the source is put on hold');
    (new Scheduler(services()))->scheduledRun();
    expect(RunState::load() === null && services()->lock()->holder() === null, 'a scheduled run does not retry the rejected credentials');

    unlink(TROUBLE_FILE);
    services()->credentialHold()->release();
    $retried = $runner->start(Mode::Incremental, false) ?? throw new RuntimeException('FAILED: the run did not start');
    finishRun($runner);
    expect(latestRun()->id === $retried->id && latestRun()->status === 'complete', 'a run started by hand (which releases the hold) completes');
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
        'square-webhook' => squareWebhook(),
        'no-mail' => noMail(),
        'lockout' => lockout(),
        default => throw new RuntimeException("Unknown scenario {$scenario}"),
    };
}

WP_CLI::add_command('iim-e2e', scenario(...));
