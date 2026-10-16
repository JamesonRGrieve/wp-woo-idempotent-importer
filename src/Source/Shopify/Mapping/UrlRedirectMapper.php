<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/** A Shopify URL redirect → a 301 the plugin serves (a relative target is resolved at request time). */
final class UrlRedirectMapper implements Mapper
{
    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::UrlRedirect;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $target = $node->string('target');

        return new Desired(Kind::UrlRedirect, $node->id(), ['target' => $target], [LegacyPaths::normalise($node->string('path'))], [], $target);
    }
}
