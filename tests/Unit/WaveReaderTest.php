<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use DateTimeImmutable;
use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\Wave\Mapping\Fields;
use IdempotentImporter\Source\Wave\Operations;
use IdempotentImporter\Source\Wave\WaveReader;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class WaveReaderTest extends TestCase
{
    private const BASE = 'https://gql.waveapps.test';

    public function testTheBusinessIsFoundAndProductsThenArchivedProductsAreRead(): void
    {
        $transport = self::transport('v1');
        $reader = $this->reader($transport);

        $active = $reader->page(Kind::Product, null, null, '');
        $archived = $reader->page(Kind::Product, $active->endCursor, null, '');

        self::assertSame(self::BASE . WaveReader::API_PATH, $transport->requests[0]['url']);
        self::assertSame(Operations::BUSINESSES, self::body($transport, 0)['operationName']);
        self::assertSame('Bearer t', $transport->requests[0]['headers']['Authorization']);
        self::assertSame(['gid://wave/Product/101', 'gid://wave/Product/102', 'gid://wave/Product/103'], self::gids($active->nodes));
        self::assertSame(['archived:1', true], [$active->endCursor, $active->hasNextPage]);
        self::assertSame(['gid://wave/Product/104'], self::gids($archived->nodes));
        self::assertFalse($archived->hasNextPage);
        self::assertTrue(self::variables($transport, 2)['isArchived']);
    }

    public function testCustomersAreCustomersContactsAndBusinessesCompanies(): void
    {
        $reader = $this->reader(self::transport('v1'));

        self::assertSame(['gid://wave/Customer/201', 'gid://wave/Customer/202', 'gid://wave/Customer/203'], self::gids($reader->page(Kind::Customer, null, null, '')->nodes));
        self::assertSame(['gid://wave/Company/201'], self::gids($reader->page(Kind::CrmCompany, null, null, '')->nodes), 'only the business');
        self::assertSame('gid://wave/Contact/202', $reader->page(Kind::Contact, null, null, '')->nodes[1]->id());
    }

    public function testAnIncrementalRunAsksForWhatChanged(): void
    {
        $transport = self::transport('v1');

        $this->reader($transport)->page(Kind::Order, null, new DateTimeImmutable('2026-09-01T10:00:00-07:00'), '');

        self::assertSame('2026-09-01T17:00:00Z', self::variables($transport, 1)['modifiedAtAfter']);
        self::assertTrue($this->reader($transport)->isIncremental(Kind::Order));
    }

    public function testAnUnauthenticatedAnswerStopsTheRun(): void
    {
        $answer = ['errors' => [['message' => 'Not authenticated', 'extensions' => ['code' => 'UNAUTHENTICATED']]], 'data' => null];
        $this->expectException(CredentialsRejected::class);
        $this->reader(new ScriptedTransport(null, [new HttpResponse(200, (string) json_encode($answer))]))->page(Kind::Customer, null, null, '');
    }

    public function testAnyOtherGraphQlErrorIsAFailedRead(): void
    {
        $answer = ['errors' => [['message' => 'Field "x" not found', 'extensions' => ['code' => 'GRAPHQL_VALIDATION_FAILED']]]];
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Wave refused Businesses: Field "x" not found');
        $this->reader(new ScriptedTransport(null, [new HttpResponse(200, (string) json_encode($answer))]))->page(Kind::Customer, null, null, '');
    }

    public function testSeveralBusinessesNeedAChosenOne(): void
    {
        $answer = ['data' => ['businesses' => ['pageInfo' => ['currentPage' => 1, 'totalPages' => 1], 'edges' => [
            ['node' => ['id' => 'QnVzaW5lc3M6YQ==', 'name' => 'Studio']],
            ['node' => ['id' => 'QnVzaW5lc3M6Yg==', 'name' => 'Shop']],
        ]]]];
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('several businesses: enter the id of the one to mirror (Studio: QnVzaW5lc3M6YQ==, Shop: QnVzaW5lc3M6Yg==)');
        $this->reader(new ScriptedTransport(null, [new HttpResponse(200, (string) json_encode($answer))]))->page(Kind::Customer, null, null, '');
    }

    public function testRecordIdsComeFromWavesGlobalIds(): void
    {
        self::assertSame('101', Fields::recordId(base64_encode('Business:abc;Product:101')));
        self::assertSame('not-a-global-id_', Fields::recordId('not-a-global-id/'), 'otherwise the id, path-safe');
    }

    private function reader(ScriptedTransport $transport): WaveReader
    {
        return WaveReader::connect(self::BASE, BearerAuthorizer::token('t', 'Wave'), $transport, new RecordingSleeper(), null);
    }

    private static function transport(string $state): ScriptedTransport
    {
        return new ScriptedTransport(new FixtureResponder('wave', $state));
    }

    /** @return array<string, mixed> a request's decoded body */
    private static function body(ScriptedTransport $transport, int $index): array
    {
        $body = json_decode((string) $transport->requests[$index]['body'], true);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    /** @return array<string, mixed> a request's GraphQL variables */
    private static function variables(ScriptedTransport $transport, int $index): array
    {
        $variables = self::body($transport, $index)['variables'] ?? null;
        self::assertIsArray($variables);

        /** @var array<string, mixed> $variables */
        return $variables;
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<string>
     */
    private static function gids(array $nodes): array
    {
        return array_map(static fn(Node $node): string => $node->id(), $nodes);
    }
}
