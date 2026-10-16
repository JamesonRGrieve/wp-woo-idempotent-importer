<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A discount code → a WooCommerce coupon, when WooCommerce applies it the same way: a percentage off the
 * whole order or off some products and categories, or an amount off the whole order; optionally with free
 * shipping, a minimum order amount checked before the discount, a use limit and validity dates (whole days,
 * the end date included). An amount off some products (Lightspeed doesn't say whether per item or in total),
 * a discount on shipping costs, a minimum checked after the discount, or a discount taken after tax (B2B) is
 * Unsupported. An inactive code maps to nothing, so its coupon is retired. Lightspeed's automatic discount
 * rules (no code) aren't read: WooCommerce applies discounts only through coupons.
 */
final class DiscountMapper implements Mapper
{
    private const VERSION = 1;
    private const UNLIMITED = 0;
    private const PERCENTAGE = 'percentage';
    private const AMOUNT = 'price';
    private const APPLIES_TO_ALL = 'all';
    private const APPLIES_TO_PRODUCTS = ['products', 'productscategories'];
    private const APPLIES_TO_CATEGORIES = ['categories', 'productscategories'];
    private const SHIPPING_UNCHANGED = 'default';
    private const SHIPPING_FREE = 'free';
    private const DAY_START = 'T00:00:00Z';
    private const DAY_END = 'T23:59:59Z';
    private const DETAILS = ['applyTo', 'shipment', 'allowCombination', 'before_tax', 'minimum_after', 'timesUsed'];

    public function kind(): Kind
    {
        return Kind::Discount;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): ?Desired
    {
        if (($node->raw()['isActive'] ?? false) !== true) {
            return null;
        }
        $code = $node->string('code');
        $type = $node->string('type');
        $applyTo = Fields::text($node, 'applyTo') ?? self::APPLIES_TO_ALL;
        $discountType = match (true) {
            $type === self::PERCENTAGE => 'percent',
            $type === self::AMOUNT && $applyTo === self::APPLIES_TO_ALL => 'fixed_cart',
            $type === self::AMOUNT => throw new Unsupported("\"{$code}\" takes an amount off some products; Lightspeed doesn't say whether per item or in total, so no WooCommerce coupon type matches it exactly."),
            default => throw new Unsupported("\"{$code}\" is a {$type} discount; WooCommerce coupons can't apply it the same way."),
        };
        $shipping = Fields::text($node, 'shipment') ?? self::SHIPPING_UNCHANGED;
        if ($shipping !== self::SHIPPING_UNCHANGED && $shipping !== self::SHIPPING_FREE) {
            throw new Unsupported("\"{$code}\" also discounts shipping costs; a WooCommerce coupon can only make shipping free.");
        }
        if (($node->raw()['before_tax'] ?? true) === false) {
            throw new Unsupported("\"{$code}\" is taken after tax; WooCommerce takes coupons before tax.");
        }
        $minimum = Fields::setAmount($node, 'minimumAmount');
        if ($minimum !== null && ($node->raw()['minimum_after'] ?? false) === true) {
            throw new Unsupported("\"{$code}\" checks its minimum order amount after the discount; WooCommerce checks it before.");
        }
        $usageLimit = $node->optionalInt('usageLimit') ?? self::UNLIMITED;
        $start = Fields::text($node, 'startDate');
        $end = Fields::text($node, 'endDate');

        return new Desired(Kind::Discount, $node->id(), [
            'title' => $code,
            'codes' => [$code],
            'starts_at' => $start === null ? Fields::requiredTime($node, 'createdAt') : $start . self::DAY_START,
            'expires_at' => $end === null ? null : $end . self::DAY_END,
            'usage_limit' => $usageLimit === self::UNLIMITED ? null : $usageLimit,
            'once_per_customer' => false,
            'emails' => [],
            'minimum_subtotal' => $minimum,
            'free_shipping' => $shipping === self::SHIPPING_FREE,
            'discount_type' => $discountType,
            'amount' => Fields::decimalOrZero($node, 'discount'),
            'product_gids' => in_array($applyTo, self::APPLIES_TO_PRODUCTS, true) ? self::ids($node, 'products', Fields::productId(...)) : [],
            'variant_gids' => [],
            'collection_gids' => in_array($applyTo, self::APPLIES_TO_CATEGORIES, true) ? self::ids($node, 'categories', Fields::categoryId(...)) : [],
            'discount' => Fields::details($node, self::DETAILS),
        ]);
    }

    /**
     * The identities of the products or categories a code is limited to (sent whole, by id).
     *
     * @param callable(int): string $identity
     *
     * @return list<string>
     */
    private static function ids(Node $discount, string $field, callable $identity): array
    {
        $raw = $discount->raw()[$field] ?? [];

        return is_array($raw) && $raw !== [] ? array_map(static fn(Node $item): string => $identity($item->int('id')), $discount->list($field)) : [];
    }
}
