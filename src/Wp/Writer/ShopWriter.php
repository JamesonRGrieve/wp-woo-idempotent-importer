<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Store\RedirectStore;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Meta\WpMeta;
use IdempotentImporter\Wp\Options;
use IdempotentImporter\Wp\Posts;
use IdempotentImporter\Wp\Retirement;

/**
 * Store settings (opt-in) and the shop's policies as pages, wired to WordPress's privacy page and
 * WooCommerce's terms page. Each policy is its own linked page with its old URL redirected to it; a
 * source's policies that are gone are retired (never another source's). Null policies mean the source
 * couldn't say (e.g. a missing access scope), so the policy pages are left as they are.
 */
final class ShopWriter implements Writer
{
    /** A policy's role → the WordPress/WooCommerce setting that names its page. */
    private const POLICY_PAGE_OPTIONS = [
        'privacy' => 'wp_page_for_privacy_policy',
        'terms' => 'woocommerce_terms_page_id',
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
        if ($f->raw('policies') !== null) {
            $this->policies($f, Identity::prefixOf($desired->gid));
        }

        return self::NO_OBJECT;
    }

    /** Each policy as its linked page; this source's policies that are gone are retired. */
    private function policies(Values $f, string $sourcePrefix): void
    {
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
            $this->redirects->syncObject(Kind::Policy, $gid, $this->targets->settings->redirects ? $policy->strings('legacy_paths') : []);
            $role = $policy->nullableString('role');
            $option = $role === null ? null : self::POLICY_PAGE_OPTIONS[$role] ?? null;
            if ($option !== null && Options::int($option) !== $pageId) {
                update_option($option, $pageId);
            }
            $kept[] = $gid;
        }
        foreach ($this->targets->links->withPrefix(Kind::Policy, $sourcePrefix) as $policyLink) {
            if (!in_array($policyLink->gid, $kept, true)) {
                Retirement::post($policyLink->targetId);
                $this->targets->links->delete(Kind::Policy, $policyLink->gid);
                $this->redirects->syncObject(Kind::Policy, $policyLink->gid, []);
            }
        }
    }

    public function exists(int $targetId): bool
    {
        return true;
    }

    public function retire(Link $link): void
    {
        // The shop itself is never retired.
    }

    /**
     * Store-wide options, written only when they differ (so an unchanged shop writes nothing). A setting
     * the source doesn't have (null, or no address at all) leaves the option as it is.
     */
    private function settings(Values $f): void
    {
        $address = $f->record('address');
        $country = $address?->nullableString('country');
        $state = $address?->nullableString('state');
        $includesTax = $f->raw('prices_include_tax');
        $options = [
            'blogname' => $f->string('name'),
            'woocommerce_email_from_address' => $f->nullableString('email'),
            'woocommerce_currency' => $f->nullableString('currency'),
            'woocommerce_weight_unit' => $f->nullableString('weight_unit'),
            'timezone_string' => $f->nullableString('timezone'),
            'woocommerce_prices_include_tax' => is_bool($includesTax) ? ($includesTax ? 'yes' : 'no') : null,
        ];
        if ($address !== null) {
            $options += [
                'woocommerce_store_address' => $address->nullableString('address_1') ?? '',
                'woocommerce_store_address_2' => $address->nullableString('address_2') ?? '',
                'woocommerce_store_city' => $address->nullableString('city') ?? '',
                'woocommerce_store_postcode' => $address->nullableString('postcode') ?? '',
                'woocommerce_default_country' => $country === null ? '' : ($state === null ? $country : "{$country}:{$state}"),
            ];
        }
        foreach ($options as $name => $value) {
            if ($value !== null && get_option($name) !== $value) {
                update_option($name, $value);
            }
        }
    }
}
