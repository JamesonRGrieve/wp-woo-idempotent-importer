<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Shopify\ShopifyError;
use IdempotentImporter\Shopify\ShopifyNode;
use PHPUnit\Framework\TestCase;

final class ShopifyNodeTest extends TestCase
{
    public function testTypedReads(): void
    {
        $node = new ShopifyNode([
            'id' => 'gid://shopify/Product/1',
            'n' => '42',
            'price' => '10.50',
            'rate' => 0.05,
            'empty' => '',
            'tags' => ['a', 'b'],
            'conn' => ['nodes' => [['id' => 'x']]],
            'list' => [['id' => 'y']],
        ]);

        self::assertSame(42, $node->int('n'));
        self::assertSame('10.5', $node->decimal('price'));
        self::assertSame('0.05', $node->decimal('rate'));
        self::assertNull($node->optionalString('empty'));
        self::assertSame(['a', 'b'], $node->strings('tags'));
        self::assertSame('x', $node->list('conn')[0]->id());
        self::assertSame('y', $node->list('list')[0]->id());
        self::assertSame([], $node->list('missing'));
        self::assertNull($node->child('missing'));
    }

    public function testMismatchNamesThePath(): void
    {
        $node = new ShopifyNode(['child' => ['title' => 5]], 'Product');

        $this->expectException(ShopifyError::class);
        $this->expectExceptionMessage('Product.child.title is not a string');
        $node->requiredChild('child')->string('title');
    }

    public function testNonNumericDecimalIsRejected(): void
    {
        $this->expectException(ShopifyError::class);
        (new ShopifyNode(['price' => 'ten']))->decimal('price');
    }
}
