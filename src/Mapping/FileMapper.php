<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Mapping;

use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Shopify\ShopifyNode;

/** A file from Shopify's Files section → a media library attachment. */
final class FileMapper implements Mapper
{
    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::File;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(ShopifyNode $node): Desired
    {
        $url = $node->child('image')?->string('url')
            ?? $node->child('originalSource')?->string('url')
            ?? $node->optionalString('url');
        if ($url === null) {
            throw new Unsupported("File {$node->id()} has no downloadable URL yet (still processing in Shopify).");
        }

        return new Desired(Kind::File, $node->id(), [
            'url' => HtmlImages::identity($url),
            'download_url' => $url,
            'alt' => $node->optionalString('alt'),
            'type' => $node->typename(),
        ]);
    }
}
