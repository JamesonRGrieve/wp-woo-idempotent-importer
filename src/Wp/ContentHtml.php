<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp;

use IdempotentShopifyImporter\Mapping\HtmlImages;

/** Rich text from Shopify, made ready for WordPress: optionally cleaned, with its images localised. */
final class ContentHtml
{
    public function __construct(
        private readonly MediaLibrary $media,
        private readonly bool $clean,
        private readonly bool $localiseImages,
    ) {}

    public function prepare(string $html): string
    {
        if ($this->clean) {
            $html = wp_kses_post($html);
        }
        if (!$this->localiseImages) {
            return $html;
        }
        $replacements = [];
        foreach (HtmlImages::shopifyUrls($html) as $url) {
            $replacements[$url] = $this->media->localUrl($url);
        }

        return HtmlImages::replace($html, $replacements);
    }
}
