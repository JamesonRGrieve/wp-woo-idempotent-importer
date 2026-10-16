<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Magento\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A cart price rule with coupons → WooCommerce coupons, one per code. Only what a WooCommerce coupon applies
 * exactly is mirrored: a percentage of each item, a fixed amount off each item or a fixed amount off the
 * cart, for every item and every customer group, at most after a minimum subtotal, optionally with free
 * shipping for the whole cart, with its date range (in the store's timezone), uses per coupon and once per
 * customer. Everything else (rules without a coupon, buy X get Y, item conditions, other cart conditions,
 * discount caps and steps, shipping discounts, free shipping for matching items, customer groups, coupons
 * with their own limits) is Unsupported. An inactive rule, or one not on the store's website, maps to
 * nothing, so its coupons are retired. Labels, priority, description and usage counts are mirrored as meta.
 */
final class DiscountMapper implements Mapper
{
    private const VERSION = 1;
    private const NO_COUPON = 'NO_COUPON';
    private const UNLIMITED = 0;
    private const ONCE = 1;
    /** Magento rule action → WooCommerce discount type, for the actions a WooCommerce coupon applies the same way. */
    private const ACTIONS = ['by_percent' => 'percent', 'by_fixed' => 'fixed_product', 'cart_fixed' => 'fixed_cart'];
    private const NO_FREE_SHIPPING = '0';
    private const FREE_SHIPPING_FOR_CART = '2';
    private const COMBINE_ALL = 'all';
    private const COMBINE_TRUE = '1';
    private const SUBTOTAL = 'base_subtotal';
    private const AT_LEAST = '>=';
    private const DETAILS = [
        'description', 'store_labels', 'website_ids', 'customer_group_ids', 'sort_order', 'stop_rules_processing',
        'is_advanced', 'is_rss', 'coupon_type', 'use_auto_generation', 'times_used', 'simple_action', 'product_ids',
    ];

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
        $name = $node->optionalString('name') ?? "Rule {$node->int('rule_id')}";
        $store = $node->requiredChild('store');
        if (!$node->bool('is_active') || !in_array($store->int('website_id'), $node->ints('website_ids'), true)) {
            return null;
        }
        if ($node->string('coupon_type') === self::NO_COUPON) {
            throw new Unsupported("\"{$name}\" applies without a coupon; WooCommerce applies discounts only through coupon codes.");
        }
        $action = $node->optionalString('simple_action') ?? '';
        $discountType = self::ACTIONS[$action] ?? throw new Unsupported("\"{$name}\" is a {$action} rule; WooCommerce coupons can't apply it the same way.");
        self::requireExpressible($name, $node);
        $coupons = $node->list('coupons');
        $perCoupon = $node->int('uses_per_coupon');
        $perCustomer = $node->int('uses_per_customer');
        foreach ($coupons as $coupon) {
            if (($coupon->optionalInt('usage_limit') ?? $perCoupon) !== $perCoupon || ($coupon->optionalInt('usage_per_customer') ?? $perCustomer) !== $perCustomer || $coupon->optionalString('expiration_date') !== null) {
                throw new Unsupported("\"{$name}\" has a coupon with its own limits or expiry; WooCommerce coupons share their discount's.");
            }
        }
        $timezone = $store->optionalString('timezone');
        $startsAt = Fields::dayStart($node->optionalString('from_date'), $timezone) ?? self::firstCreated($coupons);

        return new Desired(Kind::Discount, $node->id(), [
            'title' => $name,
            'codes' => array_values(array_filter(array_map(static fn(Node $coupon): ?string => $coupon->optionalString('code'), $coupons))),
            'starts_at' => $startsAt,
            'expires_at' => Fields::dayStart($node->optionalString('to_date'), $timezone, 1),
            'usage_limit' => $perCoupon === self::UNLIMITED ? null : $perCoupon,
            'once_per_customer' => $perCustomer === self::ONCE,
            'emails' => [],
            'minimum_subtotal' => self::minimumSubtotal($name, $node->child('condition')),
            'free_shipping' => ($node->optionalString('simple_free_shipping') ?? self::NO_FREE_SHIPPING) === self::FREE_SHIPPING_FOR_CART,
            'discount_type' => $discountType,
            'amount' => Fields::decimalOrZero($node, 'discount_amount'),
            'product_gids' => [],
            'variant_gids' => [],
            'collection_gids' => [],
            'rule' => Fields::details($node, self::DETAILS),
            'coupons' => array_map(static fn(Node $coupon): array => $coupon->only(['coupon_id', 'code', 'type', 'is_primary', 'times_used', 'created_at']), $coupons),
        ]);
    }

    /** @throws Unsupported when the rule does something a WooCommerce coupon can't */
    private static function requireExpressible(string $name, Node $rule): void
    {
        if (self::hasConditions($rule->child('action_condition'))) {
            throw new Unsupported("\"{$name}\" applies only to some items; WooCommerce coupons can't express Magento's item conditions.");
        }
        if (!Decimal::isZero($rule->optionalDecimal('discount_qty')) || ($rule->optionalInt('discount_step') ?? 0) > 0) {
            throw new Unsupported("\"{$name}\" caps or steps the discounted quantity; WooCommerce coupons can't.");
        }
        if (($rule->raw()['apply_to_shipping'] ?? false) === true) {
            throw new Unsupported("\"{$name}\" also discounts shipping; WooCommerce coupons can't.");
        }
        $freeShipping = $rule->optionalString('simple_free_shipping') ?? self::NO_FREE_SHIPPING;
        if (!in_array($freeShipping, [self::NO_FREE_SHIPPING, self::FREE_SHIPPING_FOR_CART], true)) {
            throw new Unsupported("\"{$name}\" ships some items free; a WooCommerce coupon frees shipping for the whole order or not at all.");
        }
        if (array_diff($rule->ints('all_customer_group_ids'), $rule->ints('customer_group_ids')) !== []) {
            throw new Unsupported("\"{$name}\" is limited to some customer groups; WooCommerce coupons can only restrict by email.");
        }
        if ($rule->int('uses_per_customer') > self::ONCE) {
            throw new Unsupported("\"{$name}\" can be used {$rule->int('uses_per_customer')} times per customer; WooCommerce coupons limit a customer to once or not at all.");
        }
    }

    /** The rule's minimum subtotal, the only cart condition a WooCommerce coupon can check. */
    private static function minimumSubtotal(string $name, ?Node $condition): ?string
    {
        if (!self::hasConditions($condition) || $condition === null) {
            return null;
        }
        $parts = $condition->list('conditions');
        $only = count($parts) === 1 ? $parts[0] : null;
        if ($condition->optionalString('aggregator_type') !== self::COMBINE_ALL || $condition->optionalString('value') !== self::COMBINE_TRUE
            || $only === null || self::hasConditions($only) || $only->optionalString('attribute_name') !== self::SUBTOTAL
            || $only->optionalString('operator') !== self::AT_LEAST || !is_numeric($only->optionalString('value') ?? '')) {
            throw new Unsupported("\"{$name}\" has a condition beyond a minimum subtotal; WooCommerce coupons can't express it.");
        }

        return Decimal::of($only->string('value'));
    }

    private static function hasConditions(?Node $condition): bool
    {
        return $condition !== null && $condition->list('conditions') !== [];
    }

    /** @param list<Node> $coupons */
    private static function firstCreated(array $coupons): string
    {
        $times = array_filter(array_map(static fn(Node $coupon): ?string => Fields::time($coupon, 'created_at'), $coupons));
        sort($times);

        return $times[0] ?? '';
    }
}
