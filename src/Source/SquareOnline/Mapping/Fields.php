<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\SquareOnline\Mapping;

use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SquareOnline\SquareOnlineAdapter;
use IdempotentImporter\Support\Currency;
use IdempotentImporter\Support\Decimal;
use InvalidArgumentException;

/** Field readers shared by the Square Online mappers, so each Square shape is translated in exactly one place. */
final class Fields
{
    /** Square's placeholder for an unknown country. */
    private const UNKNOWN_COUNTRY = 'ZZ';
    private const WHOLE_NUMBER = '/^-?\d+$/';

    /**
     * A Money object (`amount` in the currency's smallest unit, `currency`) as an exact decimal string.
     *
     * @throws Unsupported for a currency without a minor unit (gold, bitcoin), which WooCommerce can't price in
     */
    public static function money(Node $node, string $field): ?string
    {
        $money = $node->child($field);
        $amount = $money?->optionalInt('amount');
        if ($money === null || $amount === null) {
            return null;
        }
        try {
            return Currency::fromMinorUnits($amount, $money->string('currency'));
        } catch (InvalidArgumentException $e) {
            throw new Unsupported("{$field}: {$e->getMessage()}");
        }
    }

    public static function moneyOrZero(Node $node, string $field): string
    {
        return self::money($node, $field) ?? '0';
    }

    /** An RFC 3339 timestamp field as ISO 8601 UTC. */
    public static function time(Node $node, string $field): ?string
    {
        return Times::iso($node, $field);
    }

    public static function requiredTime(Node $node, string $field): string
    {
        return Times::requiredIso($node, $field);
    }

    /**
     * A quantity (Square sends decimal strings) as the whole number WooCommerce counts in.
     *
     * @throws Unsupported for a fractional quantity (an item sold by weight or another measurement unit)
     */
    public static function wholeQuantity(string $quantity, string $what): int
    {
        $normalised = Decimal::normalise($quantity);
        if (preg_match(self::WHOLE_NUMBER, $normalised) !== 1) {
            throw new Unsupported("{$what} has the fractional quantity {$quantity}; WooCommerce counts whole units.");
        }

        return (int) $normalised;
    }

    /** Plain multi-line text as HTML: blank lines separate paragraphs, single newlines become breaks. */
    public static function html(?string $value): string
    {
        $text = trim(str_replace("\r\n", "\n", $value ?? ''));
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
        return Slugs::fromTitle($title);
    }

    public static function id(string $type, string $id): string
    {
        return Identity::of(SquareOnlineAdapter::ID, $type, $id);
    }

    /** The Square id of a node the reader identified (the last part of its identity). */
    public static function squareId(Node $node): string
    {
        $gid = $node->id();

        return substr($gid, strrpos($gid, '/') + 1);
    }

    /**
     * A variation's identity. Square variation ids are stable across edits and are what an order line names
     * (`catalog_object_id`), so the product mapper and the order mapper both use this.
     */
    public static function variationId(string $variationId): string
    {
        return self::id('ItemVariation', $variationId);
    }

    public static function itemId(string $itemId): string
    {
        return self::id('Item', $itemId);
    }

    /**
     * The images an object references, in its order, from the image objects the reader attached
     * (`image_objects`); an image Square no longer has, or one without a URL, is left out.
     *
     * @param list<string> $imageIds
     *
     * @return list<array{url: string, alt: ?string}>
     */
    public static function images(Node $owner, array $imageIds): array
    {
        $byId = [];
        foreach ($owner->list('image_objects') as $image) {
            $data = $image->child('image_data');
            $url = $data?->optionalString('url');
            if ($data !== null && $url !== null) {
                $byId[$image->string('id')] = ['url' => $url, 'alt' => $data->optionalString('caption')];
            }
        }

        return array_values(array_filter(array_map(static fn(string $id): ?array => $byId[$id] ?? null, $imageIds)));
    }

    public static function isDeleted(Node $object): bool
    {
        return ($object->raw()['is_deleted'] ?? false) === true;
    }

    /**
     * A Square Address in WooCommerce's shape. Address lines 2 and 3 share address_2; the name, company and
     * phone come from the address's owner when Square keeps them beside it.
     *
     * @return array<string, string>
     */
    public static function address(?Node $address, ?string $firstName = null, ?string $lastName = null, ?string $company = null, ?string $phone = null): array
    {
        $country = $address?->optionalString('country');
        $lines = array_filter([$address?->optionalString('address_line_2'), $address?->optionalString('address_line_3')], static fn(?string $line): bool => $line !== null);

        return array_filter([
            'first_name' => $address?->optionalString('first_name') ?? $firstName,
            'last_name' => $address?->optionalString('last_name') ?? $lastName,
            'company' => $company,
            'address_1' => $address?->optionalString('address_line_1'),
            'address_2' => $lines === [] ? null : implode(', ', $lines),
            'city' => $address?->optionalString('locality'),
            'state' => $address?->optionalString('administrative_district_level_1'),
            'postcode' => $address?->optionalString('postal_code'),
            'country' => $country === self::UNKNOWN_COUNTRY ? null : $country,
            'phone' => $phone,
        ], static fn(?string $value): bool => $value !== null && $value !== '');
    }
}
