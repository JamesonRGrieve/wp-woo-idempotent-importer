<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

/**
 * A source that pushes change notifications: how its webhooks are authenticated and which objects each
 * one names. The endpoint only queues those objects; each is re-read through the same sync as a run, so a
 * webhook can never write differently from a scheduled run.
 */
interface WebhookReceiver
{
    /**
     * Whether the request really comes from the source (a constant-time signature check).
     *
     * @param \Closure(string): string $header a request header by (case-insensitive) name, '' when absent
     * @param string $url the URL the request was sent to, as registered with the source
     */
    public function isAuthentic(string $rawBody, \Closure $header, string $url): bool;

    /**
     * The objects an authentic webhook concerns (none for topics the importer doesn't mirror).
     *
     * @param \Closure(string): string $header
     * @param array<array-key, mixed> $payload
     *
     * @return list<WebhookEvent>
     */
    public function events(\Closure $header, array $payload): array;
}
