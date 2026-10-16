<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Tests\Unit;

use IdempotentShopifyImporter\Mapping\Desired;
use IdempotentShopifyImporter\Mapping\Hasher;
use IdempotentShopifyImporter\Model\Kind;
use PHPUnit\Framework\TestCase;

final class HasherTest extends TestCase
{
    public function testObjectKeyOrderDoesNotChangeTheHash(): void
    {
        $a = new Desired(Kind::Page, 'gid://shopify/Page/1', ['title' => 'A', 'seo' => ['title' => 't', 'description' => 'd']]);
        $b = new Desired(Kind::Page, 'gid://shopify/Page/1', ['seo' => ['description' => 'd', 'title' => 't'], 'title' => 'A']);

        self::assertSame(Hasher::hash($a, 1), Hasher::hash($b, 1));
    }

    public function testListOrderChangesTheHash(): void
    {
        $a = new Desired(Kind::Product, 'g', ['tags' => ['a', 'b']]);
        $b = new Desired(Kind::Product, 'g', ['tags' => ['b', 'a']]);

        self::assertNotSame(Hasher::hash($a, 1), Hasher::hash($b, 1));
    }

    public function testMappingVersionAndRedirectsAreHashed(): void
    {
        $base = new Desired(Kind::Page, 'g', ['title' => 'A'], ['/pages/a']);

        self::assertNotSame(Hasher::hash($base, 1), Hasher::hash($base, 2));
        self::assertNotSame(Hasher::hash($base, 1), Hasher::hash(new Desired(Kind::Page, 'g', ['title' => 'A'], ['/pages/b']), 1));
    }

    public function testWarningsAreNotHashed(): void
    {
        $quiet = new Desired(Kind::Product, 'g', ['title' => 'A']);
        $warned = new Desired(Kind::Product, 'g', ['title' => 'A'], [], ['no SKU']);

        self::assertSame(Hasher::hash($quiet, 1), Hasher::hash($warned, 1));
    }
}
