<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

/** Typed access to Etsy's OpenAPI document: its schemas, and the 200 response schema of a GET path. */
final readonly class EtsySpec
{
    /**
     * @param array<string, array<string, mixed>> $schemas components.schemas
     * @param array<string, array<string, mixed>> $paths path template → operations
     */
    private function __construct(public array $schemas, private array $paths) {}

    public static function decode(mixed $document): self
    {
        $document = Json::object($document, 'the document');

        return new self(
            self::objects(Json::object($document['components'] ?? null, 'components')['schemas'] ?? null, 'components.schemas'),
            self::objects($document['paths'] ?? null, 'paths'),
        );
    }

    /**
     * The 200 JSON response schema of a GET on a concrete path (`/v3/application/shops/5001/listings`),
     * matched against the spec's path templates; null when the spec has no such operation.
     *
     * @return array<string, mixed>|null
     */
    public function responseSchema(string $path): ?array
    {
        foreach ($this->paths as $template => $operations) {
            $pattern = '#^' . preg_replace('/\\\\\{[a-z_]+\\\\\}/', '[0-9]+', preg_quote($template, '#')) . '$#';
            if (preg_match($pattern, $path) !== 1 || !isset($operations['get'])) {
                continue;
            }
            $schema = self::at($operations, ['get', 'responses', '200', 'content', 'application/json', 'schema']);

            return $schema === null ? null : Json::object($schema, "GET {$template} 200 schema");
        }

        return null;
    }

    /**
     * The value at a key path, or null when any step is missing.
     *
     * @param list<string> $keys
     */
    private static function at(mixed $value, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /** @return array<string, array<string, mixed>> */
    private static function objects(mixed $value, string $what): array
    {
        $objects = [];
        foreach (Json::object($value, $what) as $key => $item) {
            $objects[$key] = Json::object($item, "{$what}.{$key}");
        }

        return $objects;
    }
}
