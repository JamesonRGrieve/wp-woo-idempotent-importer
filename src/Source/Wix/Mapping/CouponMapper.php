<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\Wix\Catalog;

/**
 * A Wix Stores coupon → a WooCommerce coupon. Only what a WooCommerce coupon applies exactly is mirrored: a
 * percentage off (the whole store, a product or a collection), an amount off the whole order, or free
 * shipping, with its minimum subtotal, start and expiry, use limit and once-per-customer limit. Fixed-price
 * and buy-X-get-Y coupons, an amount off some products, a coupon limited to one item, coupons usable more
 * than once per customer and coupons for other Wix apps (Bookings, Events, Pricing Plans) are Unsupported.
 * A deactivated coupon maps to nothing, so its code is retired. Usage counts aren't mirrored, so a sale
 * doesn't rewrite the coupon.
 */
final class CouponMapper implements Mapper
{
    private const VERSION = 1;
    private const STORES_NAMESPACE = 'stores';
    private const PRODUCT_GROUP = 'product';
    private const COLLECTION_GROUP = 'collection';
    private const ONCE = 1;
    private const UNSUPPORTED_TYPES = ['fixedPriceAmount' => 'fixed-price', 'buyXGetY' => 'buy-X-get-Y'];
    private const DETAILS = ['type', 'tags', 'appliesToSubscriptions', 'discountedCycleCount', 'scope', 'limitedToOneItem'];

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
        $spec = $node->requiredChild('specification');
        if (($spec->raw()['active'] ?? true) === false) {
            return null;
        }
        $name = $spec->optionalString('name') ?? $spec->string('code');
        foreach (self::UNSUPPORTED_TYPES as $field => $type) {
            if ($spec->has($field)) {
                throw new Unsupported("\"{$name}\" is a {$type} coupon; WooCommerce coupons can't apply it.");
            }
        }
        if (($spec->raw()['limitedToOneItem'] ?? false) === true) {
            throw new Unsupported("\"{$name}\" discounts only one item; the WooCommerce coupon writer doesn't limit items.");
        }
        $perCustomer = $spec->optionalInt('limitPerCustomer');
        if ($perCustomer !== null && $perCustomer > self::ONCE) {
            throw new Unsupported("\"{$name}\" can be used {$perCustomer} times per customer; WooCommerce coupons limit a customer to once or not at all.");
        }
        [$productIds, $collectionIds] = self::scope($name, $spec->child('scope'), Catalog::from($node->string('catalog')));
        $freeShipping = ($spec->raw()['freeShipping'] ?? false) === true;
        $percent = $spec->optionalDecimal('percentOffRate');
        $amountOff = $spec->optionalDecimal('moneyOffAmount');
        if ($amountOff !== null && ($productIds !== [] || $collectionIds !== [])) {
            throw new Unsupported("\"{$name}\" takes an amount off some products; a WooCommerce coupon takes it off each item or the whole cart.");
        }
        if ($percent === null && $amountOff === null && !$freeShipping) {
            throw new Unsupported("\"{$name}\" has no discount a WooCommerce coupon can apply.");
        }

        return new Desired(Kind::Discount, $node->id(), [
            'title' => $name,
            'codes' => [$spec->string('code')],
            'starts_at' => Fields::epochMillisecondsTime($spec, 'startTime') ?? Fields::epochMillisecondsTime($node, 'dateCreated')
                ?? throw new SourceError("Wix coupon {$node->id()} has no start or creation time."),
            'expires_at' => Fields::epochMillisecondsTime($spec, 'expirationTime'),
            'usage_limit' => $spec->optionalInt('usageLimit'),
            'once_per_customer' => $perCustomer === self::ONCE,
            'emails' => [],
            'minimum_subtotal' => Fields::setAmount($spec->optionalDecimal('minimumSubtotal')),
            'free_shipping' => $freeShipping,
            'discount_type' => $percent !== null ? 'percent' : 'fixed_cart',
            'amount' => $percent ?? $amountOff ?? '0',
            'product_gids' => array_map(Fields::productId(...), $productIds),
            'variant_gids' => [],
            'collection_gids' => array_map(static fn(string $id): string => Fields::collectionId(Catalog::from($node->string('catalog')), $id), $collectionIds),
            'coupon' => $spec->only(self::DETAILS),
            'display' => $node->child('displayData')?->raw(),
            'app_id' => $node->optionalString('appId'),
        ]);
    }

    /**
     * The products or collections a coupon is limited to (none: the whole store).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function scope(string $name, ?Node $scope, Catalog $catalog): array
    {
        if ($scope === null) {
            return [[], []];
        }
        if ($scope->string('namespace') !== self::STORES_NAMESPACE) {
            throw new Unsupported("\"{$name}\" is a {$scope->string('namespace')} coupon; only Wix Stores coupons apply to the store.");
        }
        $group = $scope->child('group');
        $entityId = $group?->optionalString('entityId');

        return match ($group?->optionalString('name')) {
            null => [[], []],
            self::PRODUCT_GROUP => [$entityId === null ? [] : [$entityId], []],
            self::COLLECTION_GROUP => [[], $entityId === null ? [] : [$entityId]],
            default => throw new Unsupported("\"{$name}\" applies to a {$group->optionalString('name')} group of a {$catalog->name} catalog, which WooCommerce coupons can't target."),
        };
    }
}
