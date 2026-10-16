<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * Developer aid: prints a Shopify schema type's fields, or one field's arguments and return type.
 * Usage: php tools/schema-field.php Type [field]
 */

use GraphQL\Type\Definition\HasFieldsType;
use IdempotentShopifyImporter\Shopify\ApiVersion;
use IdempotentShopifyImporter\Tools\SchemaCache;

require __DIR__ . '/../vendor/autoload.php';

$typeName = $argv[1] ?? '';
$fieldName = $argv[2] ?? null;
$type = SchemaCache::load(ApiVersion::PINNED)->getType($typeName);
if (!$type instanceof HasFieldsType) {
    fwrite(STDERR, "{$typeName} is not an object or interface type.\n");
    exit(1);
}
foreach ($type->getFields() as $field) {
    if ($fieldName !== null && $field->name !== $fieldName) {
        continue;
    }
    $args = array_map(static fn($arg): string => "{$arg->name}: {$arg->getType()}", $field->args);
    $deprecated = $field->isDeprecated() ? '  [deprecated]' : '';
    fwrite(STDOUT, "{$field->name}(" . implode(', ', $args) . "): {$field->getType()}{$deprecated}\n");
}
