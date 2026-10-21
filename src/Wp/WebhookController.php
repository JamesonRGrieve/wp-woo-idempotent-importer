<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Source\WebhookReceiver;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/idempotent-import/v1/webhook. Authenticated by the active source's signature over the raw
 * body (constant time); it only queues the objects the webhook names, so the source gets its 200 at once.
 * Registered only while the active source pushes webhooks (Shopify and Square do).
 */
final class WebhookController
{
    public const NAMESPACE = 'idempotent-import/v1';
    public const ROUTE = '/webhook';
    /**
     * The URL exactly as registered with the source, when it differs from WordPress's own (a proxy in
     * front of the site): Square signs the notification URL together with the body.
     */
    public const URL_OVERRIDE = 'IIM_WEBHOOK_URL';

    public function __construct(private readonly WebhookReceiver $receiver, private readonly Scheduler $scheduler) {}

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
        $override = defined(self::URL_OVERRIDE) ? constant(self::URL_OVERRIDE) : null;

        return is_string($override) && $override !== '' ? $override : rest_url(self::NAMESPACE . self::ROUTE);
    }

    public function isAuthentic(WP_REST_Request $request): bool
    {
        return $this->receiver->isAuthentic($request->get_body(), self::headers($request), self::url());
    }

    public function receive(WP_REST_Request $request): WP_REST_Response
    {
        $payload = json_decode($request->get_body(), true);
        foreach (is_array($payload) ? $this->receiver->events(self::headers($request), $payload) : [] as $event) {
            $this->scheduler->enqueueObject($event->kind, $event->gid, $event->deletion);
        }

        return new WP_REST_Response(null, 200);
    }

    /** @return \Closure(string): string */
    private static function headers(WP_REST_Request $request): \Closure
    {
        return static fn(string $name): string => (string) $request->get_header($name);
    }
}
