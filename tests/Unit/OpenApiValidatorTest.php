<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Tools\OpenApiValidator;
use PHPUnit\Framework\TestCase;

final class OpenApiValidatorTest extends TestCase
{
    private const SCHEMAS = [
        'Money' => ['type' => 'object', 'properties' => [
            'amount' => ['type' => 'integer'],
            'divisor' => ['type' => 'integer', 'minimum' => 1],
        ]],
        'Listing' => ['type' => 'object', 'properties' => [
            'listing_id' => ['type' => 'integer'],
            'state' => ['type' => 'string', 'enum' => ['active', 'draft']],
            'note' => ['type' => 'string', 'nullable' => true],
            'price' => ['oneOf' => [['$ref' => '#/components/schemas/Money']]],
            'sale' => ['oneOf' => [['$ref' => '#/components/schemas/Money']], 'nullable' => true],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]],
    ];

    public function testACompleteValidObjectPasses(): void
    {
        self::assertSame([], $this->errors(self::listing()));
    }

    public function testEveryDeclaredPropertyMustBePresent(): void
    {
        $listing = self::listing();
        unset($listing['note']);

        self::assertSame(['$.note is missing'], $this->errors($listing));
    }

    public function testAnUndeclaredPropertyIsRejected(): void
    {
        self::assertSame(['$.colour is not in the spec'], $this->errors([...self::listing(), 'colour' => 'red']));
    }

    public function testTypesEnumsNullabilityAndBoundsAreChecked(): void
    {
        $listing = [
            ...self::listing(),
            'listing_id' => '601',
            'state' => 'sold',
            'price' => null,
            'sale' => ['amount' => 1, 'divisor' => 0],
            'tags' => ['mug', 7],
        ];

        self::assertSame([
            '$.listing_id should be integer, got string',
            '$.state is not one of active, draft',
            '$.price is null but not nullable',
            '$.sale.divisor is below 1',
            '$.tags[1] should be string, got int',
        ], $this->errors($listing));
    }

    public function testWithoutCompleteObjectsOnlyRequiredPropertiesMustBePresent(): void
    {
        $schema = ['type' => 'object', 'required' => ['id'], 'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
        ]];
        $validator = new OpenApiValidator([], '#/definitions/', false);

        self::assertSame([], $validator->validate(['id' => 1], $schema));
        self::assertSame([], $validator->validate(['id' => 1, 'name' => null], $schema), 'an optional property may be null');
        self::assertSame(['$.id is missing'], $validator->validate(['name' => 'Mug'], $schema));
        self::assertSame(['$.sku is not in the spec'], $validator->validate(['id' => 1, 'sku' => 'A'], $schema));
    }

    public function testAllOfMembersAreMergedAndMapsTakeAnyKey(): void
    {
        $schemas = [
            'Base' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
            'Product' => ['allOf' => [
                ['$ref' => '#/components/schemas/Base'],
                ['type' => 'object', 'properties' => ['meta' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]]],
            ]],
        ];
        $validator = new OpenApiValidator($schemas, '#/components/schemas/', false);
        $product = ['$ref' => '#/components/schemas/Product'];

        self::assertSame([], $validator->validate(['id' => 1, 'meta' => ['care' => 'wash', 'origin' => 'CA']], $product));
        self::assertSame(['$.id is missing', '$.meta.size should be string, got int'], $validator->validate(['meta' => ['size' => 3]], $product));
    }

    /** @return array<string, mixed> */
    private static function listing(): array
    {
        return [
            'listing_id' => 601,
            'state' => 'active',
            'note' => null,
            'price' => ['amount' => 3200, 'divisor' => 100],
            'sale' => null,
            'tags' => ['mug'],
        ];
    }

    /** @return list<string> */
    private function errors(mixed $value): array
    {
        return (new OpenApiValidator(self::SCHEMAS, '#/components/schemas/', true))->validate($value, ['$ref' => '#/components/schemas/Listing']);
    }
}
