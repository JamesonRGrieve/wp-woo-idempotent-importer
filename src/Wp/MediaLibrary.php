<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Mapping\HtmlImages;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Shopify\HttpTransport;
use IdempotentImporter\Shopify\ShopifyError;
use IdempotentImporter\Store\LinkStore;

/**
 * Copies Shopify-hosted files into the media library once each. A file is identified by its URL without
 * the cache-busting query, so the same image used by a product, a page and the Files section is one
 * attachment.
 */
final class MediaLibrary
{
    public const DOWNLOAD_TIMEOUT_SECONDS = 120;
    public const MAX_BYTES = 200 * 1024 * 1024;
    public const SOURCE_URL_META = '_mirror_source_url';
    private const KEY_PREFIX = 'media:';

    public function __construct(
        private readonly LinkStore $links,
        private readonly HttpTransport $transport,
    ) {}

    public function attachment(string $url, ?string $alt = null, int $parentId = 0): int
    {
        $key = self::key($url);
        $existing = $this->links->targetId(Kind::Media, $key);
        if ($existing !== null && get_post($existing) !== null) {
            $this->setAlt($existing, $alt);

            return $existing;
        }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $file = $this->transport->download($url, self::DOWNLOAD_TIMEOUT_SECONDS, self::MAX_BYTES);
        $name = basename((string) parse_url(HtmlImages::identity($url), PHP_URL_PATH));
        $id = media_handle_sideload(['name' => $name, 'tmp_name' => $file], $parentId);
        if (is_wp_error($id)) {
            wp_delete_file($file);
            throw new ShopifyError("Could not add {$name} to the media library: " . $id->get_error_message());
        }
        update_post_meta($id, self::SOURCE_URL_META, HtmlImages::identity($url));
        $this->setAlt($id, $alt);
        $this->links->save(Kind::Media, $key, $id);

        return $id;
    }

    /** The local URL of a Shopify-hosted file, copying it in first if needed. */
    public function localUrl(string $url): string
    {
        $local = wp_get_attachment_url($this->attachment($url));

        return is_string($local) ? $local : $url;
    }

    private function setAlt(int $attachmentId, ?string $alt): void
    {
        $alt ??= '';
        if (get_post_meta($attachmentId, '_wp_attachment_image_alt', true) !== $alt) {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $alt);
        }
    }

    private static function key(string $url): string
    {
        return self::KEY_PREFIX . hash('sha256', HtmlImages::identity($url));
    }
}
