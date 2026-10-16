<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Source\SourceError;

/** HttpTransport over the WordPress HTTP API. */
final class WpHttpTransport implements HttpTransport
{
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        $response = wp_remote_post($url, ['headers' => $headers, 'body' => $body, 'timeout' => $timeoutSeconds]);
        if (is_wp_error($response)) {
            throw new SourceError('Could not reach Shopify: ' . $response->get_error_message());
        }
        $retryAfter = wp_remote_retrieve_header($response, 'retry-after');

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            wp_remote_retrieve_body($response),
            is_string($retryAfter) && $retryAfter !== '' ? $retryAfter : null,
        );
    }

    public function download(string $url, int $timeoutSeconds, int $maxBytes): string
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = wp_tempnam($url);
        $response = wp_safe_remote_get($url, [
            'timeout' => $timeoutSeconds,
            'stream' => true,
            'filename' => $file,
            'limit_response_size' => $maxBytes,
        ]);
        if (is_wp_error($response)) {
            wp_delete_file($file);
            throw new SourceError("Could not download {$url}: " . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            wp_delete_file($file);
            throw new SourceError("Could not download {$url}: HTTP {$status}");
        }
        if ((int) filesize($file) >= $maxBytes) {
            wp_delete_file($file);
            throw new SourceError("{$url} is larger than the {$maxBytes}-byte media limit.");
        }

        return $file;
    }
}
