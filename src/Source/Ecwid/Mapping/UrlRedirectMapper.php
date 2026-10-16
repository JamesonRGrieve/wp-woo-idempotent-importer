<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Ecwid\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * An Instant Site redirect → a redirect the plugin serves. A target on the store's own site (or a path) is an
 * old storefront URL, so it targets that path, which resolves at request time to wherever its object was
 * mirrored; a target elsewhere keeps its URL (see LegacyPaths::redirectTarget). Ecwid doesn't say which
 * status its redirects answer with; the plugin's are permanent.
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
        $fromUrl = $node->string('fromUrl');
        $from = LegacyPaths::pathOf($fromUrl) ?? throw new Unsupported("The redirect from \"{$fromUrl}\" would redirect the home page.");
        $storeUrl = $node->optionalString('store_url');
        $storeHost = $storeUrl === null ? null : parse_url($storeUrl, PHP_URL_HOST);
        $target = LegacyPaths::redirectTarget($node->string('toUrl'), is_string($storeHost) ? $storeHost : null)
            ?? throw new Unsupported("The redirect from {$from} redirects to the home page.");

        return new Desired(Kind::UrlRedirect, $node->id(), ['target' => $target], [$from], [], $target);
    }
}
