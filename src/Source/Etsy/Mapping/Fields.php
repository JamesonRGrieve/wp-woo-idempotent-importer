<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy\Mapping;

use IdempotentImporter\Source\Etsy\EtsyAdapter;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Support\Decimal;

/** Field readers shared by the Etsy mappers, so each Etsy shape is translated in exactly one place. */
final class Fields
{
    /** Etsy weight unit → WooCommerce weight unit. */
    public const WEIGHT_UNITS = ['g' => 'g', 'kg' => 'kg', 'oz' => 'oz', 'lb' => 'lbs'];

    private const ISO_FORMAT = 'Y-m-d\TH:i:s\Z';
    private const DECIMAL_BASE = 10;

    /** A Money object (`amount` / `divisor`) as an exact decimal string. */
    public static function money(Node $node, string $field): ?string
    {
        $money = $node->child($field);
        if ($money === null) {
            return null;
        }
        $divisor = $money->int('divisor');
        $places = (int) round(log($divisor, self::DECIMAL_BASE));
        if ($divisor < 1 || self::DECIMAL_BASE ** $places !== $divisor) {
            throw new SourceError("{$field} has a divisor ({$divisor}) that isn't a power of ten.");
        }

        return Decimal::shift((string) $money->int('amount'), -$places);
    }

    public static function moneyOrZero(Node $node, string $field): string
    {
        return self::money($node, $field) ?? '0';
    }

    /** A Unix timestamp as ISO 8601 UTC. */
    public static function iso(int $seconds): string
    {
        return gmdate(self::ISO_FORMAT, $seconds);
    }

    /** A Unix timestamp field as ISO 8601 UTC. */
    public static function time(Node $node, string $field): ?string
    {
        $seconds = $node->optionalInt($field);

        return $seconds === null ? null : self::iso($seconds);
    }

    public static function requiredTime(Node $node, string $field): string
    {
        return self::time($node, $field) ?? throw new SourceError("{$field} is missing.");
    }

    /** Etsy text arrives with HTML entities encoded (`&quot;`, `&#39;`); this is the plain text. */
    public static function text(?string $value): ?string
    {
        return $value === null ? null : html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Plain multi-line text as HTML: blank lines separate paragraphs, single newlines become breaks. */
    public static function html(?string $value): string
    {
        $text = trim(str_replace("\r\n", "\n", self::text($value) ?? ''));
        if ($text === '') {
            return '';
        }
        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];

        return implode("\n", array_map(
            static fn(string $paragraph): string => '<p>' . nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) . '</p>',
            $paragraphs,
        ));
    }

    /** A URL slug from a title (WordPress keeps slugs unique itself). */
    public static function slug(string $title): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) self::text($title))), '-');
    }

    public static function id(string $type, int|string $id): string
    {
        return Identity::of(EtsyAdapter::ID, $type, $id);
    }

    /**
     * A variant's key: its listing plus the property values that pick it, ordered by property
     * ("123" for a simple listing, "123-45.67" for values 45 and 67). Etsy renumbers inventory product ids
     * on every inventory edit, so they can't identify a variant; this key is the same on the listing's
     * inventory and on a receipt's transaction.
     *
     * @param array<int, int|string> $valueIdsByProperty property id → value id(s)
     */
    public static function variantKey(int $listingId, array $valueIdsByProperty): string
    {
        ksort($valueIdsByProperty);
        $suffix = implode('.', $valueIdsByProperty);

        return $suffix === '' ? (string) $listingId : "{$listingId}-{$suffix}";
    }

    /** @param array<int, int|string> $valueIdsByProperty */
    public static function variantId(int $listingId, array $valueIdsByProperty): string
    {
        return self::id('ListingVariant', self::variantKey($listingId, $valueIdsByProperty));
    }

    /**
     * A receipt's address in WooCommerce's shape. Etsy gives one name for the recipient; it is split at
     * the first space.
     *
     * @return array<string, string>
     */
    public static function address(Node $receipt): array
    {
        $name = trim((string) self::text($receipt->optionalString('name')));
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, null);

        return array_filter([
            'first_name' => $first === '' ? null : $first,
            'last_name' => $last,
            'address_1' => self::text($receipt->optionalString('first_line')),
            'address_2' => self::text($receipt->optionalString('second_line')),
            'city' => self::text($receipt->optionalString('city')),
            'state' => $receipt->optionalString('state'),
            'postcode' => $receipt->optionalString('zip'),
            'country' => $receipt->optionalString('country_iso'),
        ], static fn(?string $value): bool => $value !== null && $value !== '');
    }
}
