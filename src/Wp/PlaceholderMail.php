<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Mapping\PlaceholderEmail;

/**
 * No mail is ever sent to a placeholder address (PlaceholderEmail): whatever sends it (WooCommerce, FluentCRM,
 * FluentBoards, a password reset), every `.invalid` recipient is dropped from To, Cc and Bcc before WordPress's
 * mailer runs, and a message left with no recipient at all is not sent. Always on, not only while importing.
 */
final class PlaceholderMail
{
    private const PRIORITY = PHP_INT_MAX;
    private const COPY_HEADER = '/^\s*(cc|bcc)\s*:\s*(.*)$/i';

    public static function register(): void
    {
        add_filter('wp_mail', self::withoutPlaceholders(...), self::PRIORITY);
        add_filter('pre_wp_mail', self::skipWithoutRecipients(...), self::PRIORITY, 2);
    }

    /**
     * @param array<array-key, mixed> $message wp_mail's arguments (to, subject, message, headers, attachments)
     *
     * @return array<array-key, mixed>
     */
    public static function withoutPlaceholders(array $message): array
    {
        $message['to'] = PlaceholderEmail::deliverable(self::addresses($message['to'] ?? []));
        $headers = $message['headers'] ?? [];
        $lines = is_array($headers) ? $headers : preg_split('/\r?\n/', is_string($headers) ? $headers : '');
        $kept = [];
        foreach ($lines === false ? [] : $lines as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match(self::COPY_HEADER, $line, $match) === 1) {
                $copies = PlaceholderEmail::deliverable(self::addresses($match[2]));
                if ($copies !== []) {
                    $kept[] = "{$match[1]}: " . implode(', ', $copies);
                }

                continue;
            }
            $kept[] = $line;
        }
        $message['headers'] = $kept;

        return $message;
    }

    /**
     * @param array<array-key, mixed> $message
     *
     * @return bool|null false (not sent) when no recipient is left, else whatever an earlier filter decided
     */
    public static function skipWithoutRecipients(mixed $short, array $message): ?bool
    {
        if ($short !== null) {
            return is_bool($short) ? $short : null;
        }

        if (self::addresses($message['to'] ?? []) !== []) {
            return null;
        }
        foreach (is_array($message['headers'] ?? null) ? $message['headers'] : [] as $line) {
            if (is_string($line) && preg_match(self::COPY_HEADER, $line) === 1) {
                return null;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function addresses(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', is_string($value) ? $value : '');

        return array_values(array_filter(array_map(static fn(mixed $address): string => is_string($address) ? trim($address) : '', $list), static fn(string $address): bool => $address !== ''));
    }
}
