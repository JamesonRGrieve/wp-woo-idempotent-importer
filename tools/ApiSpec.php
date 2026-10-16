<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

/**
 * Typed access to one published API document (OpenAPI 3 or Swagger 2): its schemas, and the 200 response
 * schema of an operation on a concrete request path. A request path is matched after stripping the
 * document's server prefix (e.g. `/stores/[^/]+/v3`), given as a regular expression.
 */
final readonly class ApiSpec
{
    public const OPENAPI_REF_PREFIX = '#/components/schemas/';
    public const SWAGGER_REF_PREFIX = '#/definitions/';

    /**
     * @param array<string, array<string, mixed>> $schemas by name
     * @param array<string, array<string, mixed>> $paths path template → operations
     */
    private function __construct(public array $schemas, public string $refPrefix, private array $paths, private string $prefixPattern) {}

    public static function decode(mixed $document, string $prefixPattern): self
    {
        $document = Json::object($document, 'the document');
        $swagger = isset($document['swagger']);
        $schemas = $swagger
            ? $document['definitions'] ?? []
            : Json::object($document['components'] ?? [], 'components')['schemas'] ?? [];

        return new self(
            self::objects($schemas, 'schemas'),
            $swagger ? self::SWAGGER_REF_PREFIX : self::OPENAPI_REF_PREFIX,
            self::objects($document['paths'] ?? null, 'paths'),
            $prefixPattern,
        );
    }

    /**
     * The 200 JSON response schema of an operation on a concrete request path; null when the document has
     * no such operation.
     *
     * @return array<string, mixed>|null
     */
    public function responseSchema(string $method, string $path): ?array
    {
        $relative = $this->relative($path);
        if ($relative === null) {
            return null;
        }
        $method = strtolower($method);
        foreach ($this->paths as $template => $operations) {
            $pattern = '#^' . preg_replace('/\\\\\{[A-Za-z_]+\\\\\}/', '[^/]+', preg_quote($template, '#')) . '$#';
            if (preg_match($pattern, $relative) !== 1 || !isset($operations[$method])) {
                continue;
            }
            $schema = self::at($operations, [$method, 'responses', '200', 'content', 'application/json', 'schema'])
                ?? self::at($operations, [$method, 'responses', '200', 'schema']);

            return $schema === null ? null : Json::object($schema, "{$method} {$template} 200 schema");
        }

        return null;
    }

    /** The request path relative to the document's server prefix; null when it isn't under that prefix. */
    private function relative(string $path): ?string
    {
        if ($this->prefixPattern === '') {
            return $path;
        }
        $relative = preg_replace('#^' . $this->prefixPattern . '#', '', $path, 1, $count);

        return is_string($relative) && $count === 1 ? $relative : null;
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
