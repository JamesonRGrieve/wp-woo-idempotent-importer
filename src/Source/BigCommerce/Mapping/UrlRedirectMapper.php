<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A storefront 301 redirect → a redirect the plugin serves. A redirect to a URL keeps that URL; one to a
 * product, category, brand, page or post targets that object's storefront path, which resolves at request
 * time to wherever the object was mirrored (its own old path redirects there).
 */
final class UrlRedirectMapper implements Mapper
{
    private const VERSION = 1;
    private const URL_TARGET = 'url';

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
        $fromPath = $node->string('from_path');
        $from = Fields::path($fromPath) ?? throw new Unsupported("The redirect from \"{$fromPath}\" would redirect the home page.");
        $to = $node->requiredChild('to');
        $type = $to->string('type');
        $target = $type === self::URL_TARGET
            ? $to->optionalString('url')
            : Fields::path($node->optionalString('to_url'));
        if ($target === null) {
            throw new Unsupported("The redirect from {$from} has no target BigCommerce could resolve.");
        }

        return new Desired(Kind::UrlRedirect, $node->id(), [
            'target' => $target,
            'target_type' => $type,
            'target_entity_id' => $to->optionalInt('entity_id'),
            'site_id' => $node->optionalInt('site_id'),
        ], [$from], [], $target);
    }
}
