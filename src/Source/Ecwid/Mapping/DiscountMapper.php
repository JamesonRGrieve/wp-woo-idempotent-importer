<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Ecwid\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A discount coupon → a WooCommerce coupon, when WooCommerce applies it the same way: a percentage off
 * (optionally limited to products and categories), an amount off the whole order, or free shipping, or a
 * percentage or amount with free shipping; with a minimum order subtotal, a single use or once per customer,
 * and its launch and expiration time. An amount off some products (Ecwid doesn't say whether per item or in
 * total), a coupon limited to new or to repeat customers, to some shipping methods, or to the store's front
 * page category (`0`) is Unsupported. Only an active coupon maps; a paused, expired or used-up one maps to
 * nothing, so its coupon is retired. Its name, type and limits are mirrored as meta; the count of orders that
 * used it is volatile and not mirrored.
 */
final class DiscountMapper implements Mapper
{
    private const VERSION = 1;
    private const ACTIVE = 'ACTIVE';
    /** Ecwid coupon type → [WooCommerce coupon type, whether it also ships free]. */
    private const TYPES = [
        'PERCENT' => ['percent', false],
        'ABS' => ['fixed_cart', false],
        'SHIPPING' => ['fixed_cart', true],
        'PERCENT_AND_SHIPPING' => ['percent', true],
        'ABS_AND_SHIPPING' => ['fixed_cart', true],
    ];
    private const FIXED_CART = 'fixed_cart';
    private const SHIPPING_ONLY = 'SHIPPING';
    private const ANY_CUSTOMER = 'UNLIMITED';
    private const SINGLE_USE = 'SINGLE';
    private const ONCE_PER_CUSTOMER = 'ONCEPERCUSTOMER';
    private const SINGLE_USE_LIMIT = 1;
    /** Ecwid's store front page category: products shown there, which WooCommerce has no category for. */
    private const FRONT_PAGE_CATEGORY = 0;
    private const DETAILS = ['name', 'discountType', 'usesLimit', 'applicationLimit', 'catalogLimit', 'shippingLimit'];

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
        if ($node->optionalString('status') !== self::ACTIVE) {
            return null;
        }
        $code = $node->string('code');
        $type = $node->string('discountType');
        [$discountType, $freeShipping] = self::TYPES[$type] ?? throw new Unsupported("\"{$code}\" is a {$type} coupon; WooCommerce coupons can't apply it the same way.");
        $catalog = $node->child('catalogLimit');
        $products = $catalog?->ints('products') ?? [];
        $categories = $catalog?->ints('categories') ?? [];
        if ($discountType === self::FIXED_CART && $type !== self::SHIPPING_ONLY && ($products !== [] || $categories !== [])) {
            throw new Unsupported("\"{$code}\" takes an amount off some products; Ecwid doesn't say whether per item or in total, so no WooCommerce coupon type matches it exactly.");
        }
        if (in_array(self::FRONT_PAGE_CATEGORY, $categories, true)) {
            throw new Unsupported("\"{$code}\" is limited to the products on the store front page; WooCommerce has no such category.");
        }
        $customers = $node->optionalString('applicationLimit') ?? self::ANY_CUSTOMER;
        if ($customers !== self::ANY_CUSTOMER) {
            throw new Unsupported("\"{$code}\" is limited to {$customers} customers; a WooCommerce coupon can't tell new customers from repeat ones.");
        }
        if (($node->child('shippingLimit')?->strings('shippingMethods') ?? []) !== []) {
            throw new Unsupported("\"{$code}\" is limited to some shipping methods; a WooCommerce coupon can't be.");
        }
        $uses = $node->optionalString('usesLimit');

        return new Desired(Kind::Discount, $node->id(), [
            'title' => $node->optionalString('name') ?? $code,
            'codes' => [$code],
            'starts_at' => Fields::time($node, 'launchDate') ?? Fields::requiredTime($node, 'creationDate'),
            'expires_at' => Fields::time($node, 'expirationDate'),
            'usage_limit' => $uses === self::SINGLE_USE ? self::SINGLE_USE_LIMIT : null,
            'once_per_customer' => $uses === self::ONCE_PER_CUSTOMER,
            'emails' => [],
            'minimum_subtotal' => Fields::setAmount($node, 'totalLimit'),
            'free_shipping' => $freeShipping,
            'discount_type' => $discountType,
            'amount' => $type === self::SHIPPING_ONLY ? '0' : Fields::decimalOrZero($node, 'discount'),
            'product_gids' => array_map(Fields::productId(...), $products),
            'variant_gids' => [],
            'collection_gids' => array_map(Fields::categoryId(...), $categories),
            'discount' => $node->only(self::DETAILS),
        ]);
    }
}
