<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A 301 redirect → a redirect the plugin serves. A target path on the site resolves at request time to
 * wherever its object was mirrored; a target elsewhere keeps its URL (LegacyPaths::redirectTarget).
 */
final class RedirectMapper implements Mapper
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
        $fromUrl = $node->string('fromUrl');
        $from = LegacyPaths::pathOf($fromUrl) ?? throw new Unsupported("The redirect from \"{$fromUrl}\" would redirect the home page.");
        $target = LegacyPaths::redirectTarget($node->string('toUrl'), null)
            ?? throw new Unsupported("The redirect from {$from} redirects to the home page.");

        return new Desired(Kind::UrlRedirect, $node->id(), ['target' => $target], [$from], [], $target);
    }
}
