<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Tools\SpecCache;
use IdempotentImporter\Tools\SpecDocument;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SpecDocumentTest extends TestCase
{
    private const BODY = '{"openapi":"3.1.0"}';

    public function testADocumentPinnedByHashAcceptsOnlyThoseBytes(): void
    {
        $document = new SpecDocument('demo', 'api', 'https://example.test/openapi/api.json', '', false, SpecDocument::FORMAT_JSON, hash('sha256', self::BODY));

        $document->verify(self::BODY);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('demo/api (https://example.test/openapi/api.json) has changed');
        $document->verify(self::BODY . "\n");
    }

    public function testADocumentPinnedByVersionAcceptsWhatItsUrlServes(): void
    {
        $this->expectNotToPerformAssertions();

        (new SpecDocument('demo', 'api', 'https://example.test/v1.json', '', false))->verify('anything');
    }

    public function testEveryUnversionedBigCommerceDocumentIsPinnedByHash(): void
    {
        $documents = SpecCache::forSource('bigcommerce');

        self::assertNotEmpty($documents);
        foreach ($documents as $document) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $document->sha256 ?? '', $document->name);
            self::assertStringStartsWith('https://docs.bigcommerce.com/openapi/', $document->url);
        }
    }
}
