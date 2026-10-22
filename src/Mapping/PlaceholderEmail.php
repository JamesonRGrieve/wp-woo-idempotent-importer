<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

/**
 * A placeholder email address for a source person who has none, where WooCommerce or FluentCRM needs one (both
 * keep people by email; operator decision of 2026-10-21). It is the same every run for the same person
 * (`<type>-<id>@<source>.invalid`), so a re-run writes nothing, and its domain is under `.invalid`, which RFC 2606
 * reserves so that it can never be delivered. People mirrored with one carry PLACEHOLDER_FIELD, and no mail is
 * ever sent to one (Wp\PlaceholderMail drops every `.invalid` recipient).
 */
final class PlaceholderEmail
{
    /** The RFC 2606 top-level domain that can never resolve. */
    public const RESERVED_TLD = '.invalid';
    /** The field (and so the `_mirror_` meta) that marks a mirrored person's email as a placeholder. */
    public const PLACEHOLDER_FIELD = 'email_placeholder';
    /** An id longer than this, or with characters an address can't hold, is hashed into the local part. */
    private const MAX_ID_LENGTH = 40;
    private const HASH_LENGTH = 20;
    private const SAFE_ID = '/^[a-z0-9._-]+$/';

    /** The placeholder of one person at a source (the adapter id): the same for every kind that person is mirrored as. */
    public static function for(string $source, string $type, string $id): string
    {
        $id = strtolower($id);
        if (strlen($id) > self::MAX_ID_LENGTH || preg_match(self::SAFE_ID, $id) !== 1) {
            $id = substr(hash('sha256', $id), 0, self::HASH_LENGTH);
        }
        $domain = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($source)), '-');

        return strtolower($type) . "-{$id}@{$domain}" . self::RESERVED_TLD;
    }

    /** Whether an address can never be delivered (its domain is under `.invalid`). */
    public static function isPlaceholder(string $email): bool
    {
        $email = strtolower(trim($email, " \t\n\r\0\x0B<>"));

        return str_ends_with($email, self::RESERVED_TLD) && str_contains($email, '@');
    }

    /**
     * A person's email fields: their email (lower-cased), or their placeholder, marked, when the source has none.
     *
     * @return array<string, string|true>
     */
    public static function fields(?string $email, string $source, string $type, string $id): array
    {
        $email = $email === null ? '' : strtolower(trim($email));

        return $email === '' ? ['email' => self::for($source, $type, $id), self::PLACEHOLDER_FIELD => true] : ['email' => $email];
    }

    /**
     * The recipients that can be delivered to, from a list of addresses (each possibly `Name <address>`).
     *
     * @param list<string> $recipients
     *
     * @return list<string>
     */
    public static function deliverable(array $recipients): array
    {
        return array_values(array_filter($recipients, static function (string $recipient): bool {
            $address = preg_match('/<([^>]*)>/', $recipient, $match) === 1 ? $match[1] : $recipient;

            return trim($address) !== '' && !self::isPlaceholder($address);
        }));
    }
}
