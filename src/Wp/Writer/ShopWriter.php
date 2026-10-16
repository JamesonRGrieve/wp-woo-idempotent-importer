<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Store\RedirectStore;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Meta\WpMeta;
use IdempotentImporter\Wp\Options;
use IdempotentImporter\Wp\Posts;
use IdempotentImporter\Wp\Retirement;

/**
 * Store settings (opt-in) and the shop's policies as pages, wired to WordPress's privacy page and
 * WooCommerce's terms page. Each policy is its own linked page with its Shopify URL redirected to it.
 */
final class ShopWriter implements Writer
{
    private const POLICY_PAGE_OPTIONS = [
        'PRIVACY_POLICY' => 'wp_page_for_privacy_policy',
        'TERMS_OF_SERVICE' => 'woocommerce_terms_page_id',
    ];
    private const NO_OBJECT = 0;

    public function __construct(private readonly Targets $targets, private readonly RedirectStore $redirects) {}

    public function kind(): Kind
    {
        return Kind::Shop;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        if ($this->targets->settings->storeSettings) {
            $this->settings($f);
        }
        $kept = [];
        foreach ($f->records('policies') as $policy) {
            $gid = $policy->string('gid');
            $type = $policy->string('type');
            $pageId = Posts::upsert('page', $this->targets->links->targetId(Kind::Policy, $gid), [
                'post_title' => $policy->string('title'),
                'post_content' => $this->targets->html->prepare($policy->string('content_html')),
                'post_status' => 'publish',
            ]);
            MetaSync::fields(WpMeta::post($pageId), [MetaSync::GID => $gid, MetaSync::DELETED => null, '_mirror_policy_type' => $type]);
            $this->targets->links->save(Kind::Policy, $gid, $pageId);
            $this->redirects->syncObject(Kind::Policy, $gid, $this->targets->settings->redirects ? [LegacyPaths::policy($type)] : []);
            $option = self::POLICY_PAGE_OPTIONS[$type] ?? null;
            if ($option !== null && Options::int($option) !== $pageId) {
                update_option($option, $pageId);
            }
            $kept[] = $gid;
        }
        foreach ($this->targets->links->all(Kind::Policy) as $policyLink) {
            if (!in_array($policyLink->gid, $kept, true)) {
                Retirement::post($policyLink->targetId);
                $this->targets->links->delete(Kind::Policy, $policyLink->gid);
                $this->redirects->syncObject(Kind::Policy, $policyLink->gid, []);
            }
        }

        return self::NO_OBJECT;
    }

    public function exists(int $targetId): bool
    {
        return true;
    }

    public function retire(Link $link): void
    {
        // The shop itself is never retired.
    }

    /** Store-wide options, written only when they differ (so an unchanged shop writes nothing). */
    private function settings(Values $f): void
    {
        $address = $f->record('address');
        $country = $address?->nullableString('country');
        $state = $address?->nullableString('state');
        $options = [
            'blogname' => $f->string('name'),
            'woocommerce_email_from_address' => $f->string('email'),
            'woocommerce_currency' => $f->string('currency'),
            'woocommerce_weight_unit' => $f->string('weight_unit'),
            'timezone_string' => $f->string('timezone'),
            'woocommerce_prices_include_tax' => $f->bool('prices_include_tax') ? 'yes' : 'no',
            'woocommerce_store_address' => $address?->nullableString('address_1') ?? '',
            'woocommerce_store_address_2' => $address?->nullableString('address_2') ?? '',
            'woocommerce_store_city' => $address?->nullableString('city') ?? '',
            'woocommerce_store_postcode' => $address?->nullableString('postcode') ?? '',
            'woocommerce_default_country' => $country === null ? '' : ($state === null ? $country : "{$country}:{$state}"),
        ];
        foreach ($options as $name => $value) {
            if (get_option($name) !== $value) {
                update_option($name, $value);
            }
        }
    }
}
