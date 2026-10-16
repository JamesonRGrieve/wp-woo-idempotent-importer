<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

/**
 * Checks a JSON value against an OpenAPI 3.0 schema, strictly: every declared property must be present
 * (the source returns complete objects) and no undeclared one may appear (a fixture can't invent a field).
 * Supports the keywords Etsy's spec uses: type, nullable, enum, minimum, maximum, items, properties,
 * $ref and single-member oneOf.
 */
final class OpenApiValidator
{
    private const REF_PREFIX = '#/components/schemas/';

    /** @param array<string, mixed> $schemas the document's components.schemas */
    public function __construct(private readonly array $schemas) {}

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> every violation, each with its JSON path
     */
    public function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $schema = $this->resolve($schema);
        if ($value === null) {
            return ($schema['nullable'] ?? false) === true ? [] : ["{$path} is null but not nullable"];
        }
        $errors = [];
        $type = $schema['type'] ?? null;
        if (is_string($type) && !self::hasType($value, $type)) {
            return ["{$path} should be {$type}, got " . get_debug_type($value)];
        }
        if (is_array($schema['enum'] ?? null) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path} is not one of " . implode(', ', array_map(
                static fn(mixed $allowed): string => is_scalar($allowed) ? (string) $allowed : (string) json_encode($allowed),
                $schema['enum'],
            ));
        }
        if ((is_int($value) || is_float($value)) && is_numeric($schema['minimum'] ?? null) && $value < $schema['minimum']) {
            $errors[] = "{$path} is below {$schema['minimum']}";
        }
        if ((is_int($value) || is_float($value)) && is_numeric($schema['maximum'] ?? null) && $value > $schema['maximum']) {
            $errors[] = "{$path} is above {$schema['maximum']}";
        }
        if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
            $items = Json::object($schema['items'], "{$path} items schema");
            foreach ($value as $index => $item) {
                $errors = [...$errors, ...$this->validate($item, $items, "{$path}[{$index}]")];
            }
        }
        if (is_array($schema['properties'] ?? null) && is_array($value)) {
            $errors = [...$errors, ...$this->properties($value, $schema['properties'], $path)];
        }

        return $errors;
    }

    /**
     * @param array<array-key, mixed> $object
     * @param array<array-key, mixed> $properties
     *
     * @return list<string>
     */
    private function properties(array $object, array $properties, string $path): array
    {
        $errors = [];
        foreach ($properties as $name => $property) {
            if (!array_key_exists($name, $object)) {
                $errors[] = "{$path}.{$name} is missing";
            } else {
                $errors = [...$errors, ...$this->validate($object[$name], Json::object($property, "{$path}.{$name} schema"), "{$path}.{$name}")];
            }
        }
        foreach (array_keys($object) as $name) {
            if (!array_key_exists($name, $properties)) {
                $errors[] = "{$path}.{$name} is not in the spec";
            }
        }

        return $errors;
    }

    /**
     * Follows $ref and single-member oneOf to the schema they name, keeping the wrapper's nullable.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function resolve(array $schema): array
    {
        $nullable = ($schema['nullable'] ?? false) === true;
        while (true) {
            if (is_string($schema['$ref'] ?? null) && str_starts_with($schema['$ref'], self::REF_PREFIX)) {
                $schema = Json::object($this->schemas[substr($schema['$ref'], strlen(self::REF_PREFIX))] ?? null, "schema {$schema['$ref']}");
            } elseif (is_array($schema['oneOf'] ?? null) && count($schema['oneOf']) === 1) {
                $schema = Json::object($schema['oneOf'][0] ?? null, 'oneOf member');
            } else {
                return $nullable ? [...$schema, 'nullable' => true] : $schema;
            }
            $nullable = $nullable || ($schema['nullable'] ?? false) === true;
        }
    }

    private static function hasType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => true,
        };
    }
}
