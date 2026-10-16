<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\SquareLimits;
use IdempotentImporter\Source\Pkce;
use PHPUnit\Framework\TestCase;

final class SourceHelpersTest extends TestCase
{
    public function testPkceMatchesTheRfc7636Example(): void
    {
        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    public function testPkceVerifiersAreFreshAndUrlSafe(): void
    {
        $verifier = Pkce::verifier();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $verifier);
        self::assertNotSame($verifier, Pkce::verifier());
    }

    public function testSquareLimitsWarnAboutTooManyVariantsAndMissingSkus(): void
    {
        $variants = array_fill(0, SquareLimits::MAX_VARIATIONS + 1, ['sku' => 'A']);
        $variants[0]['sku'] = null;

        self::assertSame([
            '"Mug" has 251 variants; WooCommerce Square syncs at most 250.',
            '"Mug" has 1 variant(s) without a SKU; WooCommerce Square needs one on each.',
        ], SquareLimits::warnings('Mug', $variants));
        self::assertSame([], SquareLimits::warnings('Mug', [['sku' => 'A']]));
    }
}
