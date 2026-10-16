<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A site redirect (including those Wix made when a page's URL changed) → a redirect the plugin serves. A
 * redirect to a full URL keeps it; one to a site path targets that path, which resolves at request time to
 * wherever the object it named was mirrored (its own old path redirects there). A group redirect (every
 * path under its source) is Unsupported: the plugin redirects exact paths.
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
        $fromPath = $node->string('from');
        $from = Fields::path($fromPath) ?? throw new Unsupported("The redirect from \"{$fromPath}\" would redirect the home page.");
        if (($node->child('options')?->raw()['groupRedirect'] ?? false) === true) {
            throw new Unsupported("The redirect from {$from} redirects every path under it; the plugin redirects exact paths.");
        }
        $target = $node->string('to');

        return new Desired(Kind::UrlRedirect, $node->id(), [
            'target' => $target,
            'language' => $node->optionalString('language'),
            'created_at' => Fields::time($node, 'createdDate'),
        ], [$from], [], $target);
    }
}
