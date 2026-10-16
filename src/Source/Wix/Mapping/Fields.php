<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use DateTimeImmutable;
use DateTimeZone;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\Wix\Catalog;
use IdempotentImporter\Source\Wix\WixAdapter;
use IdempotentImporter\Support\Decimal;

/** Field readers shared by the Wix mappers, so each Wix shape is translated in exactly one place. */
final class Fields
{
    /** Wix weight units (V3 products, orders) → WooCommerce weight units. */
    public const WEIGHT_UNITS = ['KG' => 'kg', 'LB' => 'lbs'];
    /** The Wix Stores app, as an order line's catalog reference names it. */
    public const STORES_APP_ID = '215238eb-22a5-4c36-9e7b-e7c08025e04e';
    private const MILLISECONDS_PER_SECOND = 1000;
    private const SEO_TITLE_TAG = 'title';
    private const SEO_META_TAG = 'meta';
    private const SEO_DESCRIPTION = 'description';

    public static function id(string $type, string $id): string
    {
        return Identity::of(WixAdapter::ID, $type, $id);
    }

    /** The Wix id of a node the reader identified (the last part of its identity). */
    public static function sourceId(Node $node): string
    {
        $gid = $node->id();

        return substr($gid, strrpos($gid, '/') + 1);
    }

    public static function productId(string $productId): string
    {
        return self::id('Product', $productId);
    }

    /**
     * A variant's identity. Wix variant ids are stable but only unique within their product (a product
     * without options has a default variant whose id repeats across products), so the identity is the
     * product id and the variant id; an order line names both (its catalog item and `options.variantId`).
     */
    public static function variantId(string $productId, string $variantId): string
    {
        return self::id('Variant', "{$productId}:{$variantId}");
    }

    /** A V3 category or a V1 collection, by the catalog that has it. */
    public static function collectionId(Catalog $catalog, string $id): string
    {
        return self::id($catalog === Catalog::V1 ? 'Collection' : 'Category', $id);
    }

    public static function contactId(string $contactId): string
    {
        return self::id('Contact', $contactId);
    }

    /** A Wix money object (Price, FixedMonetaryAmount: a decimal string `amount`) as a decimal; null when absent. */
    public static function money(?Node $money): ?string
    {
        return $money?->optionalDecimal('amount');
    }

    public static function moneyOrZero(Node $parent, string $field): string
    {
        return self::money($parent->child($field)) ?? '0';
    }

    /** An ISO 8601 timestamp as ISO 8601 UTC; null when absent. */
    public static function time(Node $node, string $field): ?string
    {
        return Times::iso($node, $field);
    }

    public static function requiredTime(Node $node, string $field): string
    {
        return Times::requiredIso($node, $field);
    }

    /** A time Wix sends as milliseconds since the epoch (a coupon's), as ISO 8601 UTC; null when absent. */
    public static function epochMillisecondsTime(Node $node, string $field): ?string
    {
        $milliseconds = $node->optionalInt($field);
        if ($milliseconds === null) {
            return null;
        }
        $seconds = intdiv($milliseconds, self::MILLISECONDS_PER_SECOND);
        $time = DateTimeImmutable::createFromFormat('U', (string) $seconds, new DateTimeZone('UTC'))
            ?: throw new SourceError("{$field} is not a time: {$milliseconds}");

        return $time->format(Times::ISO_FORMAT);
    }

    public static function weightUnit(?string $unit): ?string
    {
        return self::WEIGHT_UNITS[$unit ?? ''] ?? null;
    }

    /** A storefront URL or path ("/product-page/mug", "https://site.example/post/hello") as its normalised path. */
    public static function path(?string $url): ?string
    {
        return LegacyPaths::pathOf($url);
    }

    /** @return list<string> the old storefront path to redirect, if there is one */
    public static function legacyPaths(?string $url): array
    {
        $path = self::path($url);

        return $path === null ? [] : [$path];
    }

    /**
     * The SEO title and description an object's SEO tags set (Wix's defaults apply when there are none).
     *
     * @return array{title: ?string, description: ?string}
     */
    public static function seo(?Node $seoData): array
    {
        $seo = ['title' => null, 'description' => null];
        foreach ($seoData?->list('tags') ?? [] as $tag) {
            if (($tag->raw()['disabled'] ?? false) === true) {
                continue;
            }
            $props = $tag->child('props');
            if ($tag->optionalString('type') === self::SEO_TITLE_TAG) {
                $seo['title'] = $tag->optionalString('children');
            } elseif ($tag->optionalString('type') === self::SEO_META_TAG && $props?->optionalString('name') === self::SEO_DESCRIPTION) {
                $seo['description'] = $props->optionalString('content');
            }
        }

        return $seo;
    }

    /** Plain text (a description Wix keeps as text) as HTML, its line breaks kept. */
    public static function textHtml(?string $text): string
    {
        return $text === null ? '' : nl2br(htmlspecialchars($text, ENT_QUOTES), false);
    }

    /**
     * An eCommerce address with its contact details (an order's billing or shipping destination) in
     * WooCommerce's shape.
     *
     * @return array<string, string>
     */
    public static function orderAddress(?Node $addressWithContact): array
    {
        if ($addressWithContact === null) {
            return [];
        }
        $contact = $addressWithContact->child('contactDetails');

        return self::filled([
            'first_name' => $contact?->optionalString('firstName'),
            'last_name' => $contact?->optionalString('lastName'),
            'company' => $contact?->optionalString('company'),
            ...self::street($addressWithContact->child('address')),
            'phone' => $contact?->optionalString('phone'),
        ]);
    }

    /**
     * A contact's saved address with its recipient in WooCommerce's shape (the contact's own name and phone
     * where the address names no recipient).
     *
     * @return array<string, string>
     */
    public static function contactAddress(Node $contactAddress, Node $contact): array
    {
        $recipient = $contactAddress->child('recipient');
        $name = $contact->child('name');

        return self::filled([
            'first_name' => $recipient?->optionalString('firstName') ?? $name?->optionalString('first'),
            'last_name' => $recipient?->optionalString('lastName') ?? $name?->optionalString('last'),
            'company' => $recipient?->optionalString('company') ?? $contact->child('company')?->optionalString('name'),
            ...self::street($contactAddress->child('address')),
            'phone' => $recipient?->optionalString('phone') ?? $contact->child('phone')?->optionalString('phone'),
        ]);
    }

    /**
     * A Wix state or province code as WooCommerce's: Wix sends ISO 3166-2 ("CA-AB", or "AB"); WooCommerce
     * keeps the part after the country ("AB").
     */
    public static function state(?string $subdivision, ?string $country): ?string
    {
        if ($subdivision === null || $country === null) {
            return $subdivision;
        }
        $prefix = strtoupper($country) . '-';

        return str_starts_with(strtoupper($subdivision), $prefix) ? substr($subdivision, strlen($prefix)) : $subdivision;
    }

    /** An amount Wix leaves at zero or out when it isn't set (a cost): null then. */
    public static function setAmount(?string $amount): ?string
    {
        return Decimal::isZero($amount) ? null : $amount;
    }

    /**
     * An address's street, city, state, postcode and country in WooCommerce's keys. Wix gives the first line
     * either as text (`addressLine`) or as its number and street name.
     *
     * @return array<string, ?string>
     */
    private static function street(?Node $address): array
    {
        if ($address === null) {
            return [];
        }
        $street = $address->child('streetAddress');
        $country = $address->optionalString('country');
        $numbered = trim(($street?->optionalString('number') ?? '') . ' ' . ($street?->optionalString('name') ?? ''));

        return [
            'address_1' => $address->optionalString('addressLine') ?? ($numbered === '' ? null : $numbered),
            'address_2' => $address->optionalString('addressLine2') ?? $street?->optionalString('apt'),
            'city' => $address->optionalString('city'),
            'state' => self::state($address->optionalString('subdivision'), $country),
            'postcode' => $address->optionalString('postalCode'),
            'country' => $country,
        ];
    }

    /**
     * @param array<string, ?string> $fields
     *
     * @return array<string, string>
     */
    private static function filled(array $fields): array
    {
        return array_filter($fields, static fn(?string $value): bool => $value !== null && $value !== '');
    }
}
