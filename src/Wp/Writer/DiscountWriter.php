<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp\Writer;

use IdempotentShopifyImporter\Mapping\Desired;
use IdempotentShopifyImporter\Mapping\Values;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Store\Link;
use IdempotentShopifyImporter\Wp\Meta\MetaSync;
use IdempotentShopifyImporter\Wp\Meta\WcDataMeta;
use IdempotentShopifyImporter\Wp\Retirement;
use WC_Coupon;

/**
 * Code discounts → one coupon per redeem code. Each code has its own link (discount GID + code), so a
 * code removed in Shopify retires just its coupon. An unlinked coupon with the same code is adopted.
 */
final class DiscountWriter implements Writer
{
    private const CODE_SEPARATOR = '#';
    private const CONSUMED = [
        'title', 'codes', 'starts_at', 'expires_at', 'usage_limit', 'once_per_customer', 'emails', 'minimum_subtotal',
        'free_shipping', 'discount_type', 'amount', 'product_gids', 'variant_gids', 'collection_gids',
    ];

    public function __construct(private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return Kind::Discount;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $productIds = [
            ...$this->targets->requireAll(Kind::Product, $f->strings('product_gids')),
            ...$this->targets->requireAll(Kind::Variant, $f->strings('variant_gids')),
        ];
        $categoryIds = $this->targets->requireAll(Kind::Collection, $f->strings('collection_gids'));
        $ids = [];
        foreach ($f->strings('codes') as $code) {
            $key = self::codeKey($desired->gid, $code);
            $coupon = new WC_Coupon($this->targets->links->targetId(Kind::DiscountCode, $key) ?? wc_get_coupon_id_by_code($code));
            $coupon->set_code($code);
            $coupon->set_description($f->string('title'));
            $coupon->set_discount_type($f->string('discount_type'));
            $coupon->set_amount($f->string('amount'));
            $coupon->set_free_shipping($f->bool('free_shipping'));
            $coupon->set_date_expires($f->nullableString('expires_at'));
            $coupon->set_usage_limit($f->nullableInt('usage_limit') ?? 0);
            $coupon->set_usage_limit_per_user($f->bool('once_per_customer') ? 1 : 0);
            $coupon->set_email_restrictions($f->strings('emails'));
            $coupon->set_minimum_amount($f->nullableString('minimum_subtotal') ?? '');
            $coupon->set_product_ids($productIds);
            $coupon->set_product_categories($categoryIds);
            MetaSync::mirror(new WcDataMeta($coupon), $desired->gid, $f, self::CONSUMED);
            MetaSync::fields(new WcDataMeta($coupon), ['_shopify_starts_at' => $f->string('starts_at')]);
            $id = $coupon->save();
            $this->targets->links->save(Kind::DiscountCode, $key, $id);
            $ids[] = $id;
        }
        $this->retireRemovedCodes($desired->gid, $f->strings('codes'));

        return $ids[0] ?? 0;
    }

    public function exists(int $targetId): bool
    {
        return $targetId === 0 || get_post($targetId) !== null;
    }

    public function retire(Link $link): void
    {
        $this->retireRemovedCodes($link->gid, []);
    }

    /** @param list<string> $keep codes that still exist */
    private function retireRemovedCodes(string $discountGid, array $keep): void
    {
        $kept = array_map(static fn(string $code): string => self::codeKey($discountGid, $code), $keep);
        foreach ($this->targets->links->withPrefix(Kind::DiscountCode, $discountGid . self::CODE_SEPARATOR) as $codeLink) {
            if (!in_array($codeLink->gid, $kept, true)) {
                Retirement::wcObject(new WC_Coupon($codeLink->targetId));
                $this->targets->links->delete(Kind::DiscountCode, $codeLink->gid);
            }
        }
    }

    /** The link key of one code: codes can be long, so the code is hashed to keep the key indexable. */
    private static function codeKey(string $discountGid, string $code): string
    {
        return $discountGid . self::CODE_SEPARATOR . hash('sha256', $code);
    }
}
