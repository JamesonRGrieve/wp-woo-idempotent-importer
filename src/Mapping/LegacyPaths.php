<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

/** The URL paths an object had on the Shopify storefront, so they can 301 to its new home. */
final class LegacyPaths
{
    private const ROOT = '/';

    /**
     * Any storefront's URL or path ("/trail-mug/", "https://shop.example/shop/p/trail-mug") as its
     * normalised path; null when there is none, or it is the home page (nothing to redirect).
     */
    public static function pathOf(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $path = self::normalise($url);

        return $path === self::ROOT ? null : $path;
    }

    public static function product(string $handle): string
    {
        return '/products/' . $handle;
    }

    public static function collection(string $handle): string
    {
        return '/collections/' . $handle;
    }

    public static function page(string $handle): string
    {
        return '/pages/' . $handle;
    }

    public static function blog(string $handle): string
    {
        return '/blogs/' . $handle;
    }

    public static function article(string $blogHandle, string $handle): string
    {
        return '/blogs/' . $blogHandle . '/' . $handle;
    }

    /** A shop policy's page, e.g. REFUND_POLICY → /policies/refund-policy. */
    public static function policy(string $type): string
    {
        return '/policies/' . str_replace('_', '-', strtolower($type));
    }

    /**
     * Normalises a requested path for lookup: lower case, no query, no trailing slash, and Shopify's
     * nested product URL (/collections/<c>/products/<p>) reduced to its product path.
     */
    public static function normalise(string $path): string
    {
        $path = strtolower((string) parse_url($path, PHP_URL_PATH));
        $path = '/' . trim($path, '/');
        if (preg_match('#^/collections/[^/]+(/products/[^/]+)$#', $path, $match) === 1) {
            return $match[1];
        }

        return $path;
    }
}
