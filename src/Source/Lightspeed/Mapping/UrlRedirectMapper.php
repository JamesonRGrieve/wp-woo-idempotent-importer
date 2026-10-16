<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A storefront redirect → a redirect the plugin serves. A target on the shop's own domain (or a path) is an
 * old storefront URL, so it targets that path, which resolves at request time to wherever its object was
 * mirrored; a target elsewhere keeps its URL. Whether Lightspeed served it as permanent is kept (the plugin
 * always redirects permanently).
 */
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
        $fromUrl = $node->string('url');
        $from = LegacyPaths::pathOf($fromUrl) ?? throw new Unsupported("The redirect from \"{$fromUrl}\" would redirect the home page.");
        $target = LegacyPaths::redirectTarget($node->string('target'), Fields::text($node, 'shop_domain'))
            ?? throw new Unsupported("The redirect from {$from} redirects to the home page.");

        return new Desired(Kind::UrlRedirect, $node->id(), [
            'target' => $target,
            'permanent' => ($node->raw()['isPermanent'] ?? true) === true,
        ], [$from], [], $target);
    }
}
