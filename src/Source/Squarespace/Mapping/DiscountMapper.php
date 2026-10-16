<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Squarespace\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A promo-code discount → a WooCommerce coupon, when a coupon applies it exactly: a percentage or fixed
 * amount off the order, or free shipping, for any order or above a minimum cart total; or a percentage off
 * one product. Its start and expiry, site-wide use limit and once-per-customer limit are kept; its
 * subscription and payment-plan settings are meta. Automatic discounts, buy-X-get-Y discounts, a fixed amount
 * or free shipping tied to a product, and free shipping limited to pickup, local delivery or one shipping
 * option are Unsupported. Redemption counts and the derived status (scheduled, active, expired) are not
 * mirrored, so a redemption doesn't rewrite the coupon.
 */
final class DiscountMapper implements Mapper
{
    private const VERSION = 1;
    private const PROMO_CODE = 'CODE';
    private const PERCENTAGE = 'PERCENTAGE';
    private const FIXED_AMOUNT = 'FIXED_AMOUNT';
    private const FREE_SHIPPING = 'FREE_SHIPPING';
    private const ANY_ORDER = 'ANY_ORDER';
    private const CART_TOTAL = 'CART_TOTAL';
    private const PRODUCT = 'PRODUCT';
    private const SHIPPING = 'SHIPPING';
    private const DETAILS = ['subscriptionOptions', 'paymentPlanOptions'];

    public function kind(): Kind
    {
        return Kind::Discount;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $name = $node->string('name');
        $trigger = $node->requiredChild('trigger');
        if ($trigger->string('type') !== self::PROMO_CODE) {
            throw new Unsupported("\"{$name}\" is applied automatically; WooCommerce applies discounts only through coupon codes.");
        }
        $template = $node->requiredChild('template');
        $templateType = $template->string('type');
        [$discountType, $amount, $freeShipping] = self::template($name, $template);
        [$minimum, $productIds] = self::criteria($name, $node->requiredChild('criteria'), $templateType);
        $limited = ($node->raw()['isLimitedUses'] ?? false) === true;

        return new Desired(Kind::Discount, $node->id(), [
            'title' => $name,
            'codes' => [$trigger->string('promoCode')],
            'starts_at' => Fields::requiredTime($node, 'validFrom'),
            'expires_at' => Fields::time($node, 'validTo'),
            'usage_limit' => $limited ? $node->optionalInt('maxUsesAllowed') : null,
            'once_per_customer' => ($node->raw()['isOncePerCustomer'] ?? false) === true,
            'emails' => [],
            'minimum_subtotal' => $minimum,
            'free_shipping' => $freeShipping,
            'discount_type' => $discountType,
            'amount' => $amount,
            'product_gids' => array_map(Fields::productId(...), $productIds),
            'variant_gids' => [],
            'collection_gids' => [],
            'discount' => $node->only(self::DETAILS),
        ]);
    }

    /**
     * The WooCommerce discount type, amount and free-shipping flag of a discount template.
     *
     * @return array{0: string, 1: string, 2: bool}
     */
    private static function template(string $name, Node $template): array
    {
        return match ($template->string('type')) {
            self::PERCENTAGE => ['percent', $template->decimal('percentage'), false],
            self::FIXED_AMOUNT => ['fixed_cart', Fields::money($template, 'discountAmount') ?? throw new Unsupported("\"{$name}\" has no discount amount."), false],
            self::FREE_SHIPPING => self::freeShipping($name, $template),
            default => throw new Unsupported("\"{$name}\" is a {$template->string('type')} discount; WooCommerce coupons can't apply it the same way."),
        };
    }

    /** @return array{0: string, 1: string, 2: bool} */
    private static function freeShipping(string $name, Node $template): array
    {
        $fulfillment = $template->optionalString('appliesToFulfillmentOptionType');
        if (($fulfillment !== null && $fulfillment !== self::SHIPPING) || $template->child('selectedShippingOption') !== null) {
            throw new Unsupported("\"{$name}\" waives only some delivery options; a WooCommerce free-shipping coupon can't be limited that way.");
        }

        return ['fixed_cart', '0', true];
    }

    /**
     * The minimum cart total and the products a discount's criteria limit it to.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private static function criteria(string $name, Node $criteria, string $templateType): array
    {
        return match ($criteria->string('type')) {
            self::ANY_ORDER => [null, []],
            self::CART_TOTAL => [Fields::money($criteria, 'minimumCartTotal'), []],
            self::PRODUCT => $templateType === self::PERCENTAGE
                ? [null, [$criteria->string('productId')]]
                : throw new Unsupported("\"{$name}\" gives an amount off or free shipping for one product; a WooCommerce coupon can't apply that the same way."),
            default => throw new Unsupported("\"{$name}\" has {$criteria->string('type')} criteria; WooCommerce coupons can't express them."),
        };
    }
}
