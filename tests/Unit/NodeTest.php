<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use PHPUnit\Framework\TestCase;

final class NodeTest extends TestCase
{
    public function testTypedReads(): void
    {
        $node = new Node([
            'id' => 'gid://shopify/Product/1',
            'n' => '42',
            'price' => '10.50',
            'rate' => 0.05,
            'empty' => '',
            'tags' => ['a', 'b'],
            'value_ids' => [1001, 1002],
            'conn' => ['nodes' => [['id' => 'x']]],
            'list' => [['id' => 'y']],
        ]);

        self::assertSame(42, $node->int('n'));
        self::assertSame('10.5', $node->decimal('price'));
        self::assertSame('0.05', $node->decimal('rate'));
        self::assertNull($node->optionalString('empty'));
        self::assertSame(['a', 'b'], $node->strings('tags'));
        self::assertSame([1001, 1002], $node->ints('value_ids'));
        self::assertSame([], $node->ints('missing'));
        self::assertSame('x', $node->list('conn')[0]->id());
        self::assertSame('y', $node->list('list')[0]->id());
        self::assertSame([], $node->list('missing'));
        self::assertNull($node->child('missing'));
    }

    public function testMismatchNamesThePath(): void
    {
        $node = new Node(['child' => ['title' => 5]], 'Product');

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Product.child.title is not a string');
        $node->requiredChild('child')->string('title');
    }

    public function testAListOfIntegersRejectsOtherValues(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('$.value_ids is not a list of integers');
        (new Node(['value_ids' => [1, '2']]))->ints('value_ids');
    }

    public function testNonNumericDecimalIsRejected(): void
    {
        $this->expectException(SourceError::class);
        (new Node(['price' => 'ten']))->decimal('price');
    }

    public function testDecimalsSentAsJsonNumbersAreExact(): void
    {
        $node = new Node(['price' => 19.99, 'rate' => 0.1, 'big' => 1e2, 'text' => '1E2', 'whole' => 7]);

        self::assertSame('19.99', $node->decimal('price'));
        self::assertSame('0.1', $node->decimal('rate'));
        self::assertSame('100', $node->decimal('big'));
        self::assertSame('100', $node->decimal('text'));
        self::assertSame('7', $node->decimal('whole'));
    }
}
