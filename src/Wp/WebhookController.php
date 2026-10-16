<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Source\Shopify\ShopifyAdapter;
use IdempotentImporter\Source\Shopify\WebhookTopics;
use IdempotentImporter\Source\Shopify\WebhookVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/idempotent-import/v1/webhook. Authenticated by Shopify's HMAC of the raw body (constant
 * time); it only queues the object it names, so Shopify gets its 200 at once. Registered only while the
 * active source pushes webhooks (Shopify is the only one that does today).
 */
final class WebhookController
{
    public const NAMESPACE = 'idempotent-import/v1';
    public const ROUTE = '/webhook';
    private const HMAC_HEADER = 'x-shopify-hmac-sha256';
    private const TOPIC_HEADER = 'x-shopify-topic';

    public function __construct(private readonly Scheduler $scheduler) {}

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => $this->receive(...),
            'permission_callback' => $this->isAuthentic(...),
        ]);
    }

    public static function url(): string
    {
        return rest_url(self::NAMESPACE . self::ROUTE);
    }

    public function isAuthentic(WP_REST_Request $request): bool
    {
        return (new WebhookVerifier(Credentials::get(ShopifyAdapter::WEBHOOK_SECRET)))
            ->isAuthentic($request->get_body(), (string) $request->get_header(self::HMAC_HEADER));
    }

    public function receive(WP_REST_Request $request): WP_REST_Response
    {
        $payload = json_decode($request->get_body(), true);
        $event = is_array($payload) ? WebhookTopics::resolve((string) $request->get_header(self::TOPIC_HEADER), $payload) : null;
        if ($event !== null) {
            $this->scheduler->enqueueObject($event->kind, $event->gid, $event->deletion);
        }

        return new WP_REST_Response(null, 200);
    }
}
