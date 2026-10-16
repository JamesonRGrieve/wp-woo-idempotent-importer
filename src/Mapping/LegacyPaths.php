<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

/** The URL paths an object had on the Shopify storefront, so they can 301 to its new home. */
final class LegacyPaths
{
    private const ROOT = '/';
    private const WWW = 'www.';

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

    /**
     * A storefront redirect's target: a path, or a URL on the store's own host ($storeHost, `www.` or not),
     * is an old storefront URL, so its path (which resolves to wherever its object was mirrored); any other
     * URL as it is. Null when it targets the home page.
     */
    public static function redirectTarget(string $target, ?string $storeHost): ?string
    {
        $host = parse_url($target, PHP_URL_HOST);
        if (!is_string($host)) {
            return self::pathOf($target);
        }

        return $storeHost !== null && self::bareHost($host) === self::bareHost($storeHost) ? self::pathOf($target) : $target;
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

    private static function bareHost(string $host): string
    {
        $host = strtolower($host);

        return str_starts_with($host, self::WWW) ? substr($host, strlen(self::WWW)) : $host;
    }
}
