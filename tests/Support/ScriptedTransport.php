<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use IdempotentImporter\Shopify\HttpResponse;
use IdempotentImporter\Shopify\HttpTransport;
use LogicException;

/**
 * The outbound HTTP boundary for unit tests: replays queued responses first, then answers from a store
 * state's fixtures, and records every request it was sent.
 */
final class ScriptedTransport implements HttpTransport
{
    /** @var list<array{url: string, headers: array<string, string>, body: array<array-key, mixed>}> */
    public array $requests = [];

    /** @param list<HttpResponse> $queued */
    public function __construct(private readonly ?FixtureResponder $responder = null, private array $queued = []) {}

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $decoded = is_array($decoded) ? $decoded : [];
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $decoded];
        $next = array_shift($this->queued);
        if ($next !== null) {
            return $next;
        }
        if ($this->responder === null) {
            throw new LogicException('No scripted response left.');
        }

        return new HttpResponse(200, json_encode($this->responder->respond($decoded), JSON_THROW_ON_ERROR));
    }

    public function operation(int $request): string
    {
        $operation = $this->requests[$request]['body']['operationName'] ?? null;

        return is_string($operation) ? $operation : '';
    }

    public function document(int $request): string
    {
        $document = $this->requests[$request]['body']['query'] ?? null;

        return is_string($document) ? $document : '';
    }

    /** @return array<array-key, mixed> */
    public function variables(int $request): array
    {
        $variables = $this->requests[$request]['body']['variables'] ?? null;

        return is_array($variables) ? $variables : [];
    }

    public function download(string $url, int $timeoutSeconds, int $maxBytes): string
    {
        throw new LogicException('Unit tests do not download.');
    }
}
