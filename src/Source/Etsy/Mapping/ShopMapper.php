<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * The shop → store settings Etsy has (name, currency) and its policies as pages: each written policy
 * section, and each structured return policy. Etsy has no store email, timezone, weight unit or address
 * to mirror, so those WooCommerce settings are left as they are.
 */
final class ShopMapper implements Mapper
{
    private const VERSION = 1;
    /** Etsy's written policy fields → page title and the WordPress page setting they fill, if any. */
    private const POLICIES = [
        'policy_welcome' => ['Welcome', null],
        'policy_payment' => ['Payment policy', null],
        'policy_shipping' => ['Shipping policy', null],
        'policy_refunds' => ['Refund policy', null],
        'policy_additional' => ['Additional policies', null],
        'policy_seller_info' => ['Seller information', null],
        'policy_privacy' => ['Privacy policy', 'privacy'],
    ];

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
        $shopId = $node->int('shop_id');
        $policies = [];
        foreach (self::POLICIES as $field => [$title, $role]) {
            $text = $node->optionalString($field);
            if ($text !== null) {
                $policies[] = [
                    'gid' => Fields::id('Policy', "{$shopId}-{$field}"),
                    'type' => $field,
                    'role' => $role,
                    'title' => $title,
                    'content_html' => Fields::html($text),
                    'legacy_paths' => [],
                ];
            }
        }
        foreach ($node->list('return_policies') as $policy) {
            $policies[] = self::returnPolicy($policy);
        }

        return new Desired(Kind::Shop, $node->id(), [
            'name' => (string) Fields::text($node->string('shop_name')),
            'email' => null,
            'currency' => $node->string('currency_code'),
            'weight_unit' => null,
            'timezone' => null,
            'prices_include_tax' => null,
            'address' => null,
            'url' => $node->string('url'),
            'title' => Fields::text($node->optionalString('title')),
            'announcement' => Fields::text($node->optionalString('announcement')),
            'sale_message' => Fields::text($node->optionalString('sale_message')),
            'vacation' => $node->bool('is_vacation') ? Fields::text($node->optionalString('vacation_message')) ?? '' : null,
            'location_country' => $node->optionalString('shop_location_country_iso'),
            'icon' => $node->optionalString('icon_url_fullxfull'),
            'policies' => $policies,
        ]);
    }

    /**
     * A structured return policy written out as a sentence (its fields are what Etsy stores; the wording
     * is ours).
     *
     * @return array<string, mixed>
     */
    private static function returnPolicy(Node $policy): array
    {
        $returns = $policy->bool('accepts_returns');
        $exchanges = $policy->bool('accepts_exchanges');
        $deadline = $policy->optionalInt('return_deadline');
        $accepted = match (true) {
            $returns && $exchanges => 'Returns and exchanges are accepted',
            $returns => 'Returns are accepted',
            $exchanges => 'Exchanges are accepted',
            default => null,
        };
        $text = $accepted === null
            ? 'Returns and exchanges are not accepted.'
            : $accepted . ($deadline === null ? '.' : " within {$deadline} days.");

        return [
            'gid' => Fields::id('ReturnPolicy', $policy->int('return_policy_id')),
            'type' => 'return_policy',
            'role' => null,
            'title' => 'Returns and exchanges',
            'content_html' => '<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>',
            'legacy_paths' => [],
        ];
    }
}
