<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Shopify;

/** A Shopify global ID, gid://shopify/<Type>/<id>. */
final readonly class Gid
{
    private const PATTERN = '#^gid://shopify/([A-Za-z]+)/([A-Za-z0-9-]+)(\?.*)?$#';

    private function __construct(public string $type, public string $id) {}

    public static function parse(string $gid): self
    {
        if (preg_match(self::PATTERN, $gid, $match) !== 1) {
            throw new ShopifyError("Not a Shopify global ID: {$gid}");
        }

        return new self($match[1], $match[2]);
    }

    public static function of(string $type, string $id): string
    {
        return "gid://shopify/{$type}/{$id}";
    }
}
