<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * Code discounts → WooCommerce coupons, one per redeem code. Only the discounts WooCommerce can apply
 * exactly are mirrored; anything that would change how a discount behaves is Unsupported instead.
 */
final class DiscountMapper implements Mapper
{
    private const VERSION = 1;
    private const PERCENT_PLACES = 2;
    private const BASIC = 'DiscountCodeBasic';
    private const FREE_SHIPPING = 'DiscountCodeFreeShipping';
    private const ALL_BUYERS = 'DiscountBuyerSelectionAll';
    private const SPECIFIC_CUSTOMERS = 'DiscountCustomers';
    private const MINIMUM_SUBTOTAL = 'DiscountMinimumSubtotal';
    private const PRODUCTS = 'DiscountProducts';
    private const COLLECTIONS = 'DiscountCollections';
    private const PERCENTAGE = 'DiscountPercentage';
    private const AMOUNT = 'DiscountAmount';

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
        $discount = $node->requiredChild('discount');
        $type = $discount->typename();
        if ($type !== self::BASIC && $type !== self::FREE_SHIPPING) {
            throw new Unsupported("\"{$discount->string('title')}\" is a {$type}; WooCommerce coupons can't express it (see coverage/shopify.md).");
        }
        $fields = [
            'title' => $discount->string('title'),
            'codes' => array_map(static fn(Node $code): string => $code->string('code'), $discount->list('codes')),
            'starts_at' => $discount->string('startsAt'),
            'expires_at' => $discount->optionalString('endsAt'),
            'usage_limit' => $discount->optionalInt('usageLimit'),
            'once_per_customer' => $discount->bool('appliesOncePerCustomer'),
            'emails' => self::customerEmails($discount),
            'minimum_subtotal' => self::minimumSubtotal($discount),
            'free_shipping' => $type === self::FREE_SHIPPING,
            'maximum_shipping_price' => $discount->child('maximumShippingPrice')?->decimal('amount'),
        ];

        return new Desired(Kind::Discount, $node->id(), $type === self::BASIC ? [...$fields, ...self::value($discount)] : [
            ...$fields,
            'discount_type' => 'fixed_cart',
            'amount' => '0',
            'product_gids' => [],
            'variant_gids' => [],
            'collection_gids' => [],
        ]);
    }

    /** @return list<string> */
    private static function customerEmails(Node $discount): array
    {
        $context = $discount->requiredChild('context');
        $type = $context->typename();
        if ($type === self::ALL_BUYERS) {
            return [];
        }
        if ($type !== self::SPECIFIC_CUSTOMERS) {
            throw new Unsupported("\"{$discount->string('title')}\" targets {$type}; WooCommerce coupons can only restrict by email.");
        }
        $emails = [];
        foreach ($context->list('customers') as $customer) {
            $email = $customer->child('defaultEmailAddress')?->optionalString('emailAddress');
            if ($email === null) {
                throw new Unsupported("\"{$discount->string('title')}\" is limited to a customer without an email address.");
            }
            $emails[] = strtolower($email);
        }

        return $emails;
    }

    private static function minimumSubtotal(Node $discount): ?string
    {
        $minimum = $discount->child('minimumRequirement');
        if ($minimum === null) {
            return null;
        }
        if ($minimum->typename() !== self::MINIMUM_SUBTOTAL) {
            throw new Unsupported("\"{$discount->string('title')}\" needs a minimum quantity; WooCommerce coupons only support a minimum spend.");
        }

        return $minimum->requiredChild('greaterThanOrEqualToSubtotal')->decimal('amount');
    }

    /** @return array<string, mixed> */
    private static function value(Node $discount): array
    {
        $gets = $discount->requiredChild('customerGets');
        $value = $gets->requiredChild('value');
        $items = $gets->requiredChild('items');
        $itemType = $items->typename();
        $targeted = $itemType === self::PRODUCTS || $itemType === self::COLLECTIONS;
        [$discountType, $amount] = match ($value->typename()) {
            self::PERCENTAGE => ['percent', Decimal::shift($value->decimal('percentage'), self::PERCENT_PLACES)],
            self::AMOUNT => [
                $value->bool('appliesOnEachItem') && $targeted ? 'fixed_product' : 'fixed_cart',
                $value->requiredChild('amount')->decimal('amount'),
            ],
            default => throw new Unsupported("\"{$discount->string('title')}\" gives a {$value->typename()}; WooCommerce coupons can't express it."),
        };

        return [
            'discount_type' => $discountType,
            'amount' => $amount,
            'product_gids' => $itemType === self::PRODUCTS ? Fields::ids($items, 'products') : [],
            'variant_gids' => $itemType === self::PRODUCTS ? Fields::ids($items, 'productVariants') : [],
            'collection_gids' => $itemType === self::COLLECTIONS ? Fields::ids($items, 'collections') : [],
        ];
    }
}
