<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

/**
 * Checks a JSON value against an OpenAPI 3 / Swagger 2 schema. No undeclared property may appear (a
 * fixture can't invent a field) unless the schema allows extra keys (`additionalProperties`). With
 * `completeObjects` every declared property must be present (sources that return complete objects);
 * otherwise only the schema's `required` ones, and an optional property may be null (Swagger 2 has no
 * `nullable`). Supports type, nullable / x-nullable, enum, minimum, maximum, items, properties, required,
 * additionalProperties, allOf, $ref and single-member oneOf/anyOf.
 */
final class OpenApiValidator
{
    /** @param array<string, array<string, mixed>> $schemas the document's schemas, by name */
    public function __construct(
        private readonly array $schemas,
        private readonly string $refPrefix,
        private readonly bool $completeObjects,
    ) {}

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> every violation, each with its JSON path
     */
    public function validate(mixed $value, array $schema, string $path = '$', bool $optional = false): array
    {
        $schema = $this->resolve($schema);
        if ($value === null) {
            return self::isNullable($schema) || $optional && !$this->completeObjects ? [] : ["{$path} is null but not nullable"];
        }
        $type = $schema['type'] ?? null;
        if (is_string($type) && !self::hasType($value, $type)) {
            return ["{$path} should be {$type}, got " . get_debug_type($value)];
        }
        $errors = [];
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
        if (is_array($value) && (isset($schema['properties']) || isset($schema['additionalProperties']))) {
            $errors = [...$errors, ...$this->object($value, $schema, $path)];
        }

        return $errors;
    }

    /**
     * @param array<array-key, mixed> $object
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function object(array $object, array $schema, string $path): array
    {
        $properties = Json::object($schema['properties'] ?? [], "{$path} properties");
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $errors = [];
        foreach ($properties as $name => $property) {
            $isRequired = $this->completeObjects || in_array($name, $required, true);
            if (!array_key_exists($name, $object)) {
                if ($isRequired) {
                    $errors[] = "{$path}.{$name} is missing";
                }
                continue;
            }
            $errors = [...$errors, ...$this->validate($object[$name], Json::object($property, "{$path}.{$name} schema"), "{$path}.{$name}", !$isRequired)];
        }
        $extra = $schema['additionalProperties'] ?? false;
        foreach ($object as $name => $value) {
            if (array_key_exists($name, $properties)) {
                continue;
            }
            if (is_array($extra)) {
                $errors = [...$errors, ...$this->validate($value, Json::object($extra, "{$path} additionalProperties"), "{$path}.{$name}")];
            } elseif ($extra !== true) {
                $errors[] = "{$path}.{$name} is not in the spec";
            }
        }

        return $errors;
    }

    /**
     * Follows $ref and single-member oneOf/anyOf to the schema they name and merges allOf members, keeping
     * the wrapper's nullable.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function resolve(array $schema): array
    {
        $nullable = self::isNullable($schema);
        while (true) {
            $one = is_array($schema['oneOf'] ?? null) ? $schema['oneOf'] : (is_array($schema['anyOf'] ?? null) ? $schema['anyOf'] : null);
            if (is_string($schema['$ref'] ?? null) && str_starts_with($schema['$ref'], $this->refPrefix)) {
                $name = substr($schema['$ref'], strlen($this->refPrefix));
                $schema = $this->schemas[$name] ?? throw new \RuntimeException("Unknown schema {$schema['$ref']}");
            } elseif ($one !== null && count($one) === 1) {
                $schema = Json::object($one[0] ?? null, 'oneOf member');
            } elseif (is_array($schema['allOf'] ?? null)) {
                $schema = $this->merge($schema);
            } else {
                return $nullable ? [...$schema, 'nullable' => true] : $schema;
            }
            $nullable = $nullable || self::isNullable($schema);
        }
    }

    /**
     * One schema from an allOf: the members' properties and required lists combined (the wrapper's own
     * keywords kept).
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function merge(array $schema): array
    {
        $members = is_array($schema['allOf']) ? $schema['allOf'] : [];
        unset($schema['allOf']);
        $properties = Json::object($schema['properties'] ?? [], 'properties');
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($members as $member) {
            $resolved = $this->resolve(Json::object($member, 'allOf member'));
            $properties = [...$properties, ...Json::object($resolved['properties'] ?? [], 'allOf member properties')];
            $required = [...$required, ...(is_array($resolved['required'] ?? null) ? $resolved['required'] : [])];
            $schema['type'] ??= $resolved['type'] ?? null;
            if (isset($resolved['additionalProperties']) && !isset($schema['additionalProperties'])) {
                $schema['additionalProperties'] = $resolved['additionalProperties'];
            }
        }

        return [...$schema, 'properties' => $properties, 'required' => array_values(array_unique($required, SORT_REGULAR))];
    }

    /** @param array<string, mixed> $schema */
    private static function isNullable(array $schema): bool
    {
        return ($schema['nullable'] ?? false) === true || ($schema['x-nullable'] ?? false) === true;
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
