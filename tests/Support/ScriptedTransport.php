<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Support;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Http\HttpTransport;
use LogicException;

/**
 * The outbound HTTP boundary for unit tests: replays queued responses first, then answers through a
 * source's fixture Responder, and records every request it was sent.
 */
final class ScriptedTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** @param list<HttpResponse> $queued */
    public function __construct(private readonly ?Responder $responder = null, private array $queued = []) {}

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        return $this->send('POST', $url, $headers, $body);
    }

    public function get(string $url, array $headers, int $timeoutSeconds): HttpResponse
    {
        return $this->send('GET', $url, $headers, '');
    }

    /** A GraphQL request's operation name. */
    public function operation(int $request): string
    {
        $operation = $this->json($request)['operationName'] ?? null;

        return is_string($operation) ? $operation : '';
    }

    /** A GraphQL request's document. */
    public function document(int $request): string
    {
        $document = $this->json($request)['query'] ?? null;

        return is_string($document) ? $document : '';
    }

    /**
     * A GraphQL request's variables.
     *
     * @return array<array-key, mixed>
     */
    public function variables(int $request): array
    {
        $variables = $this->json($request)['variables'] ?? null;

        return is_array($variables) ? $variables : [];
    }

    public function download(string $url, int $timeoutSeconds, int $maxBytes): string
    {
        throw new LogicException('Unit tests do not download.');
    }

    /** @param array<string, string> $headers */
    private function send(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->queued);
        if ($next !== null) {
            return $next;
        }
        if ($this->responder === null) {
            throw new LogicException('No scripted response left.');
        }

        return $this->responder->respond($method, $url, $body);
    }

    /** @return array<array-key, mixed> */
    private function json(int $request): array
    {
        $decoded = json_decode($this->requests[$request]['body'], true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
