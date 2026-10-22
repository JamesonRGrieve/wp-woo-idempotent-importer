<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Tools\ApiSpec;
use PHPUnit\Framework\TestCase;

final class ApiSpecTest extends TestCase
{
    public function testAnOperationsResponseIsFoundInlineOrByReference(): void
    {
        $spec = ApiSpec::decode([
            'openapi' => '3.1.0',
            'paths' => [
                '/inline' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'title' => 'inline']]]]]]],
                '/shared' => ['get' => ['responses' => ['200' => ['$ref' => '#/components/responses/Shared']]]],
                '/{module}' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['title' => 'templated']]]]]]],
            ],
            'components' => [
                'schemas' => [],
                'responses' => ['Shared' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'title' => 'shared']]]]],
            ],
        ], '');

        self::assertSame('inline', $spec->responseSchema('GET', '/inline')['title'] ?? null);
        self::assertSame('shared', $spec->responseSchema('GET', '/shared')['title'] ?? null, 'a response the document shares by reference');
        self::assertSame('templated', $spec->responseSchema('GET', '/Deals')['title'] ?? null);
        self::assertNull($spec->responseSchema('POST', '/inline'));
    }
}
