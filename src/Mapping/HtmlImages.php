<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Mapping;

/**
 * Images embedded in Shopify rich text (descriptions, pages, articles) that live on Shopify's CDN, so
 * they can be copied into the media library and the HTML pointed at the copies.
 */
final class HtmlImages
{
    private const SRC_PATTERN = '/<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/i';
    private const SHOPIFY_HOST_PATTERN = '#^(?:https?:)?//(?:cdn\.shopify\.com|[^/]+\.myshopify\.com|[^/]+/cdn/shop)/#i';

    /** @return list<string> distinct Shopify-hosted image URLs, in document order */
    public static function shopifyUrls(string $html): array
    {
        preg_match_all(self::SRC_PATTERN, $html, $matches);
        $urls = [];
        foreach ($matches[2] as $src) {
            $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5);
            if (preg_match(self::SHOPIFY_HOST_PATTERN, $src) === 1) {
                $urls[self::absolute($src)] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * Replaces each Shopify image URL (matched by its absolute form) with its new URL.
     *
     * @param array<string, string> $replacements absolute Shopify URL => new URL
     */
    public static function replace(string $html, array $replacements): string
    {
        if ($replacements === []) {
            return $html;
        }

        return (string) preg_replace_callback(self::SRC_PATTERN, static function (array $match) use ($replacements): string {
            $src = self::absolute(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5));
            if (!isset($replacements[$src])) {
                return $match[0];
            }

            return str_replace($match[2], htmlspecialchars($replacements[$src], ENT_QUOTES), $match[0]);
        }, $html);
    }

    /** The URL that identifies a media file: absolute https, without the cache-busting query. */
    public static function identity(string $url): string
    {
        $url = self::absolute($url);
        $query = strpos($url, '?');

        return $query === false ? $url : substr($url, 0, $query);
    }

    private static function absolute(string $url): string
    {
        return str_starts_with($url, '//') ? 'https:' . $url : $url;
    }
}
