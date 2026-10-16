<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Ecwid\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * The store profile → store settings: the store's name (else the company's), the company email, currency,
 * weight unit (carats have no WooCommerce unit), timezone, whether prices include tax (gross prices) and the
 * company address. Each legal page shown on the store itself (enabled, inline, with text) becomes a policy
 * page; the terms and the privacy statement are wired to WooCommerce's terms page and WordPress's privacy
 * page. The store URL and platform, its description, the company name and phone, the number and price
 * formats, languages and the store's taxes are kept with the shop (legal pages that link elsewhere among
 * them).
 */
final class ShopMapper implements Mapper
{
    private const VERSION = 1;
    /** Ecwid legal page types → the WordPress page setting they fill, if any. */
    private const POLICY_ROLES = [
        'TERMS' => 'terms',
        'PRIVACY_STATEMENT' => 'privacy',
        'LEGAL_INFO' => null,
        'SHIPPING_COST_PAYMENT_INFO' => null,
        'REVOCATION_TERMS' => null,
    ];
    private const INLINE = 'INLINE';
    private const GENERAL_DETAILS = ['storeId', 'storeUrl', 'websitePlatform', 'storefrontUrlFormat', 'storefrontUrlSlugFormat'];
    private const SETTINGS_DETAILS = ['closed', 'storeDescription', 'productReviewsFeatureEnabled'];
    private const COMPANY_DETAILS = ['companyName', 'phone'];
    private const FORMAT_DETAILS = [
        'currencyPrefix', 'currencySuffix', 'currencyPrecision', 'weightUnit', 'dimensionsUnit', 'orderNumberPrefix',
        'orderNumberSuffix',
    ];
    private const TAX_DETAILS = ['automaticTaxEnabled', 'taxes', 'taxExemptBusiness'];

    public function kind(): Kind
    {
        return Kind::Shop;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $general = $node->requiredChild('generalInfo');
        $settings = $node->child('settings');
        $company = $node->child('company');
        $formats = $node->requiredChild('formatsAndUnits');
        $taxes = $node->child('taxSettings');
        $address = Fields::address($company);
        unset($address['company'], $address['phone']);
        [$policies, $external] = self::policies($general->int('storeId'), $node->child('legalPagesSettings'));
        $includesTax = $taxes?->raw()['pricesIncludeTax'] ?? null;

        return new Desired(Kind::Shop, $node->id(), [
            'name' => $settings?->optionalString('storeName') ?? $company?->optionalString('companyName') ?? '',
            'email' => $company?->optionalString('email'),
            'currency' => $formats->string('currency'),
            'weight_unit' => Fields::weightUnit($formats->optionalString('weightUnit')),
            'timezone' => $formats->optionalString('timezone'),
            'prices_include_tax' => is_bool($includesTax) ? $includesTax : null,
            'address' => Fields::hasStreet($address) ? $address : null,
            'store' => [
                ...$general->only(self::GENERAL_DETAILS),
                ...($settings?->only(self::SETTINGS_DETAILS) ?? []),
                ...($company?->only(self::COMPANY_DETAILS) ?? []),
                ...$formats->only(self::FORMAT_DETAILS),
                ...($taxes?->only(self::TAX_DETAILS) ?? []),
                'languages' => $node->child('languages')?->raw(),
                'external_legal_pages' => $external,
            ],
            'policies' => $policies,
        ]);
    }

    /**
     * The legal pages the store shows itself, as policy pages, and those that link elsewhere.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, ?string>>}
     */
    private static function policies(int $storeId, ?Node $settings): array
    {
        $policies = [];
        $external = [];
        foreach ($settings?->list('legalPages') ?? [] as $page) {
            if (($page->raw()['enabled'] ?? false) !== true) {
                continue;
            }
            $type = $page->string('type');
            $text = $page->optionalString('text');
            if ($page->optionalString('display') !== self::INLINE || $text === null) {
                $external[] = ['type' => $type, 'title' => $page->optionalString('title'), 'url' => $page->optionalString('externalUrl')];
                continue;
            }
            $policies[] = [
                'gid' => Fields::id('Policy', "{$storeId}-{$type}"),
                'type' => $type,
                'role' => self::POLICY_ROLES[$type] ?? null,
                'title' => $page->optionalString('title') ?? $type,
                'content_html' => $text,
                'legacy_paths' => [],
            ];
        }

        return [$policies, $external];
    }
}
