<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Mapping;

use IdempotentShopifyImporter\Shopify\ShopifyNode;

/** Field readers shared by every mapper, so each Shopify shape is translated in exactly one place. */
final class Fields
{
    /** Shopify keeps a resource's SEO title/description for pages and articles in these metafields. */
    public const SEO_NAMESPACE = 'global';
    public const SEO_TITLE_KEY = 'title_tag';
    public const SEO_DESCRIPTION_KEY = 'description_tag';

    /** A MoneyBag field's shop-currency amount. */
    public static function money(ShopifyNode $node, string $field): ?string
    {
        return $node->child($field)?->requiredChild('shopMoney')->decimal('amount');
    }

    /** A MoneyBag field's amount, zero when absent. */
    public static function moneyOrZero(ShopifyNode $node, string $field): string
    {
        return self::money($node, $field) ?? '0';
    }

    /**
     * A MailingAddress as WooCommerce address fields.
     *
     * @return array<string, string>
     */
    public static function address(?ShopifyNode $address): array
    {
        if ($address === null) {
            return [];
        }

        return array_filter([
            'first_name' => $address->optionalString('firstName'),
            'last_name' => $address->optionalString('lastName'),
            'company' => $address->optionalString('company'),
            'address_1' => $address->optionalString('address1'),
            'address_2' => $address->optionalString('address2'),
            'city' => $address->optionalString('city'),
            'state' => $address->optionalString('provinceCode') ?? $address->optionalString('province'),
            'postcode' => $address->optionalString('zip'),
            'country' => $address->optionalString('countryCodeV2'),
            'phone' => $address->optionalString('phone'),
        ], static fn(?string $value): bool => $value !== null);
    }

    /**
     * Metafields keyed "<namespace>.<key>", sorted, each with its Shopify type and serialised value.
     *
     * @return array<string, array{type: string, value: string}>
     */
    public static function metafields(ShopifyNode $owner): array
    {
        $metafields = [];
        foreach ($owner->list('metafields') as $metafield) {
            $metafields[$metafield->string('namespace') . '.' . $metafield->string('key')] = [
                'type' => $metafield->string('type'),
                'value' => $metafield->string('value'),
            ];
        }
        ksort($metafields, SORT_STRING);

        return $metafields;
    }

    /**
     * SEO title/description from an SEO object, falling back to the global.* metafields.
     *
     * @param array<string, array{type: string, value: string}> $metafields
     *
     * @return array{title: ?string, description: ?string}
     */
    public static function seo(?ShopifyNode $seo, array $metafields): array
    {
        $prefix = self::SEO_NAMESPACE . '.';

        return [
            'title' => $seo?->optionalString('title') ?? ($metafields[$prefix . self::SEO_TITLE_KEY]['value'] ?? null),
            'description' => $seo?->optionalString('description') ?? ($metafields[$prefix . self::SEO_DESCRIPTION_KEY]['value'] ?? null),
        ];
    }

    /**
     * @param list<ShopifyNode> $taxLines
     *
     * @return list<array{title: string, rate: ?string, amount: string}>
     */
    public static function taxLines(array $taxLines): array
    {
        return array_map(static fn(ShopifyNode $line): array => [
            'title' => $line->string('title'),
            'rate' => $line->optionalDecimal('rate'),
            'amount' => self::moneyOrZero($line, 'priceSet'),
        ], $taxLines);
    }

    /**
     * Shopify custom attributes (key/value pairs) as a map.
     *
     * @return array<string, string>
     */
    public static function attributes(ShopifyNode $owner, string $field = 'customAttributes'): array
    {
        $attributes = [];
        foreach ($owner->list($field) as $attribute) {
            $attributes[$attribute->string('key')] = $attribute->optionalString('value') ?? '';
        }

        return $attributes;
    }

    /**
     * The `id` of every node in a list or connection.
     *
     * @return list<string>
     */
    public static function ids(ShopifyNode $owner, string $field): array
    {
        return array_map(static fn(ShopifyNode $node): string => $node->id(), $owner->list($field));
    }

    /** A nested object's `id`, or null. */
    public static function childId(ShopifyNode $owner, string $field): ?string
    {
        return $owner->child($field)?->id();
    }
}
