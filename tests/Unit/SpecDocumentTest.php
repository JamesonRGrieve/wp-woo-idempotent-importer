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

    public function testTheUnversionedSquarespaceDocumentIsPinnedByHash(): void
    {
        $documents = SpecCache::forSource('squarespace');

        self::assertCount(1, $documents);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $documents[0]->sha256 ?? '');
        self::assertStringStartsWith('https://developers.squarespace.com/commerce-apis/', $documents[0]->url);
    }

    public function testACommittedDocumentIsReadWhereItIsAndNeverFetched(): void
    {
        $document = SpecDocument::committed('demo', 'reference', 'tests/schemas/demo.yaml', '', false);

        self::assertTrue($document->isCommitted());
        self::assertSame('file://tests/schemas/demo.yaml', $document->url);
        self::assertSame(SpecDocument::FORMAT_YAML, $document->format);
        self::assertSame(dirname(__DIR__, 2) . '/tests/schemas/demo.yaml', $document->cachePath());
        self::assertFalse((new SpecDocument('demo', 'api', 'https://example.test/v1.json', '', false))->isCommitted());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('it is edited there, never fetched');
        SpecCache::write($document, self::BODY);
    }

    public function testTheCommittedWixReferenceLoadsAndNamesItsOperations(): void
    {
        $documents = SpecCache::forSource('wix');

        self::assertCount(1, $documents);
        self::assertTrue($documents[0]->isCommitted());
        $spec = SpecCache::load($documents[0]);
        self::assertNotNull($spec->responseSchema('POST', '/stores/v3/products/query'));
        self::assertNotNull($spec->responseSchema('GET', '/site-properties/v4/properties'));
        self::assertNull($spec->responseSchema('GET', '/stores/v3/products/query'), 'only the methods Wix documents');
    }

    public function testTheCommittedEcwidReferenceStripsTheStorePrefix(): void
    {
        $documents = SpecCache::forSource('ecwid');

        self::assertCount(1, $documents);
        self::assertTrue($documents[0]->isCommitted());
        $spec = SpecCache::load($documents[0]);
        self::assertNotNull($spec->responseSchema('GET', '/api/v3/7400100/products'));
        self::assertNotNull($spec->responseSchema('GET', '/api/v3/7400100/orders/CW-1001'));
        self::assertNull($spec->responseSchema('GET', '/products'), 'requests carry the /api/v3/<store id> prefix');
        self::assertNull($spec->responseSchema('POST', '/api/v3/7400100/products'), 'only reads are transcribed');
    }
}
