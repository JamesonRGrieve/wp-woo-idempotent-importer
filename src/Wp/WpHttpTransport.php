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
    private const RETRY_AFTER_HEADER = 'retry-after';
    /** BigCommerce's rate-limit hint: milliseconds until the window resets (it sends no Retry-After). */
    private const RATE_LIMIT_RESET_MS_HEADER = 'x-rate-limit-time-reset-ms';

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        return self::response($url, wp_remote_post($url, ['headers' => $headers, 'body' => $body, 'timeout' => $timeoutSeconds]));
    }

    public function get(string $url, array $headers, int $timeoutSeconds): HttpResponse
    {
        return self::response($url, wp_remote_get($url, ['headers' => $headers, 'timeout' => $timeoutSeconds]));
    }

    /** @param array<string, mixed>|\WP_Error $response */
    private static function response(string $url, array|\WP_Error $response): HttpResponse
    {
        if (is_wp_error($response)) {
            $host = wp_parse_url($url, PHP_URL_HOST);

            throw new SourceError('Could not reach ' . (is_string($host) ? $host : 'the source') . ': ' . $response->get_error_message());
        }
        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            wp_remote_retrieve_body($response),
            self::header($response, self::RETRY_AFTER_HEADER),
            self::header($response, self::RATE_LIMIT_RESET_MS_HEADER),
        );
    }

    /** @param array<string, mixed> $response */
    private static function header(array $response, string $name): ?string
    {
        $value = wp_remote_retrieve_header($response, $name);

        return is_string($value) && $value !== '' ? $value : null;
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
