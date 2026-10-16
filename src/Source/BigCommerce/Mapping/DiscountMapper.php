<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\BigCommerce\BigCommerceReader;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;

/**
 * Coupons (legacy v2 coupons and v3 coupon promotions) → WooCommerce coupons, one per code. Only what a
 * WooCommerce coupon applies exactly is mirrored: a legacy coupon's fixed amount per item, percentage, fixed
 * amount off the order (for the whole store) or free shipping (for the whole store), with its minimum spend,
 * expiry, use limit and once-per-customer limit; a promotion with codes whose one rule takes a percentage or
 * fixed amount off the order, at most after a minimum spend. Everything else (shipping discounts, country
 * or shipping-method restrictions, automatic promotions, item, gift and shipping rules, customer-group,
 * schedule and address conditions, capped discounts) is Unsupported. A disabled coupon or promotion maps to
 * nothing, so its codes are retired.
 */
final class DiscountMapper implements Mapper
{
    private const VERSION = 1;
    private const UNLIMITED = 0;
    private const ONCE = 1;
    private const PROMOTION_TYPE = 'Promotion';
    private const ENABLED = 'ENABLED';
    private const ALL_CATEGORIES = 0;
    /** Legacy coupon type → WooCommerce discount type, for the types a WooCommerce coupon applies the same way. */
    private const COUPON_TYPES = [
        'per_item_discount' => 'fixed_product',
        'percentage_discount' => 'percent',
        'per_total_discount' => 'fixed_cart',
        'free_shipping' => 'fixed_cart',
    ];
    /** Legacy coupon types that discount the whole order, so they can't be limited to some products. */
    private const ORDER_WIDE_TYPES = ['per_total_discount', 'free_shipping'];
    private const COUPON_DETAILS = ['type', 'applies_to', 'max_uses_per_customer', 'restricted_to', 'shipping_methods'];
    private const PROMOTION_DETAILS = [
        'redemption_type', 'display_name', 'channels', 'rules', 'can_be_used_with_other_promotions', 'currency_code',
        'notifications', 'promotion_category', 'stop',
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
        $gid = $node->id();

        return str_contains($gid, '/' . self::PROMOTION_TYPE . '/') ? $this->promotion($node) : $this->coupon($node);
    }

    private function coupon(Node $node): ?Desired
    {
        if (($node->raw()['enabled'] ?? true) === false) {
            return null;
        }
        $name = $node->string('name');
        $type = $node->string('type');
        $discountType = self::COUPON_TYPES[$type] ?? throw new Unsupported("\"{$name}\" is a {$type} coupon; WooCommerce coupons can't apply it the same way.");
        $restricted = $node->child('restricted_to');
        if ($node->strings('shipping_methods') !== [] || ($restricted !== null && $restricted->raw() !== [])) {
            throw new Unsupported("\"{$name}\" is limited to some countries or shipping methods; WooCommerce coupons can't be.");
        }
        $perCustomer = $node->optionalInt('max_uses_per_customer') ?? self::UNLIMITED;
        if ($perCustomer > self::ONCE) {
            throw new Unsupported("\"{$name}\" can be used {$perCustomer} times per customer; WooCommerce coupons limit a customer to once or not at all.");
        }
        [$productIds, $categoryIds] = self::appliesTo($node->child('applies_to'));
        if (in_array($type, self::ORDER_WIDE_TYPES, true) && ($productIds !== [] || $categoryIds !== [])) {
            throw new Unsupported("\"{$name}\" discounts the order only when some products are bought; a WooCommerce cart coupon can't express that.");
        }
        $maxUses = $node->optionalInt('max_uses') ?? self::UNLIMITED;

        return new Desired(Kind::Discount, $node->id(), [
            'title' => $name,
            'codes' => [$node->string('code')],
            'starts_at' => Fields::requiredTime($node, 'date_created'),
            'expires_at' => Fields::time($node, 'expires'),
            'usage_limit' => $maxUses === self::UNLIMITED ? null : $maxUses,
            'once_per_customer' => $perCustomer === self::ONCE,
            'emails' => [],
            'minimum_subtotal' => Fields::setAmount($node, 'min_purchase'),
            'free_shipping' => $type === 'free_shipping',
            'discount_type' => $discountType,
            'amount' => $type === 'free_shipping' ? '0' : Fields::decimalOrZero($node, 'amount'),
            'product_gids' => array_map(Fields::productId(...), $productIds),
            'variant_gids' => [],
            'collection_gids' => array_map(Fields::categoryId(...), $categoryIds),
            'coupon' => Fields::details($node, self::COUPON_DETAILS),
        ]);
    }

    private function promotion(Node $node): ?Desired
    {
        $name = $node->string('name');
        if ($node->string('status') !== self::ENABLED) {
            return null;
        }
        if ($node->string('redemption_type') !== BigCommerceReader::COUPON_PROMOTION) {
            throw new Unsupported("\"{$name}\" is an automatic promotion; WooCommerce applies discounts only through coupon codes.");
        }
        foreach (['shipping_address', 'schedule'] as $limit) {
            if ($node->child($limit) !== null) {
                throw new Unsupported("\"{$name}\" has a {$limit} condition; WooCommerce coupons can't express it.");
            }
        }
        self::requireEveryCustomer($name, $node->child('customer'));
        $rules = $node->list('rules');
        if (count($rules) !== 1) {
            throw new Unsupported("\"{$name}\" has " . count($rules) . ' rules; a WooCommerce coupon applies one discount.');
        }
        [$discountType, $amount] = self::orderDiscount($name, $rules[0]);
        $codes = $node->list('codes');
        foreach ($codes as $code) {
            if ($code->child('schedule') !== null || ($code->optionalInt('max_uses') ?? self::UNLIMITED) !== self::UNLIMITED || ($code->optionalInt('max_uses_per_customer') ?? self::UNLIMITED) > self::ONCE) {
                throw new Unsupported("\"{$name}\" has a code with its own schedule or use limits; WooCommerce coupons share their discount's.");
            }
        }
        $maxUses = $node->optionalInt('max_uses') ?? self::UNLIMITED;
        if ($maxUses !== self::UNLIMITED && count($codes) > 1) {
            throw new Unsupported("\"{$name}\" limits uses across {$maxUses} codes; WooCommerce limits each coupon on its own.");
        }
        $oncePerCustomer = $codes !== [] && array_filter($codes, static fn(Node $code): bool => $code->optionalInt('max_uses_per_customer') !== self::ONCE) === [];

        return new Desired(Kind::Discount, $node->id(), [
            'title' => $name,
            'codes' => array_map(static fn(Node $code): string => $code->string('code'), $codes),
            'starts_at' => Fields::requiredTime($node, 'start_date'),
            'expires_at' => Fields::time($node, 'end_date'),
            'usage_limit' => $maxUses === self::UNLIMITED ? null : $maxUses,
            'once_per_customer' => $oncePerCustomer,
            'emails' => [],
            'minimum_subtotal' => self::minimumSpend($name, $rules[0]),
            'free_shipping' => false,
            'discount_type' => $discountType,
            'amount' => $amount,
            'product_gids' => [],
            'variant_gids' => [],
            'collection_gids' => [],
            'promotion' => Fields::details($node, self::PROMOTION_DETAILS),
        ]);
    }

    /**
     * A legacy coupon's products and categories (category 0 is every product).
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function appliesTo(?Node $appliesTo): array
    {
        $ids = array_values(array_filter($appliesTo?->ints('ids') ?? [], static fn(int $id): bool => $id !== self::ALL_CATEGORIES));

        return match ($appliesTo?->optionalString('entity')) {
            'products' => [$ids, []],
            'categories', null => [[], $ids],
            default => throw new SourceError('A BigCommerce coupon applies to an unknown kind of object.'),
        };
    }

    /** @throws Unsupported when the promotion is limited to some customers */
    private static function requireEveryCustomer(string $name, ?Node $customer): void
    {
        if ($customer === null) {
            return;
        }
        if ($customer->ints('group_ids') !== [] || $customer->ints('excluded_group_ids') !== []
            || ($customer->optionalInt('minimum_order_count') ?? 0) > 0 || $customer->child('segments') !== null) {
            throw new Unsupported("\"{$name}\" is limited to some customers; WooCommerce coupons can only restrict by email.");
        }
    }

    /**
     * The WooCommerce discount type and amount of a rule that takes a percentage or fixed amount off the order.
     *
     * @return array{0: string, 1: string}
     */
    private static function orderDiscount(string $name, Node $rule): array
    {
        $cartValue = $rule->requiredChild('action')->child('cart_value')
            ?? throw new Unsupported("\"{$name}\" discounts items, shipping or gifts; only a discount off the order maps to a WooCommerce coupon.");
        if ($cartValue->has('maximum_allowed_discount_amount')) {
            throw new Unsupported("\"{$name}\" caps its discount; WooCommerce coupons can't.");
        }
        $discount = $cartValue->requiredChild('discount');
        $percentage = $discount->optionalDecimal('percentage_amount');

        return $percentage !== null
            ? ['percent', $percentage]
            : ['fixed_cart', $discount->optionalDecimal('fixed_amount') ?? throw new SourceError("\"{$name}\" has a discount with no amount.")];
    }

    /** The rule's minimum spend, the only condition a WooCommerce coupon can check. */
    private static function minimumSpend(string $name, Node $rule): ?string
    {
        $condition = $rule->child('condition');
        if ($condition === null) {
            return null;
        }
        $cart = $condition->child('cart');
        if ($cart === null || array_diff(array_keys($cart->raw()), ['minimum_spend']) !== []) {
            throw new Unsupported("\"{$name}\" has a condition beyond a minimum spend; WooCommerce coupons can't express it.");
        }

        return $cart->optionalDecimal('minimum_spend');
    }
}
