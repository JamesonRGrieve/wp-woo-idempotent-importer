<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Shopify\ShopifyNode;

/** Customer → WooCommerce customer. Passwords and saved payment methods can't leave Shopify. */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'shopify-';

    private const VERSION = 1;

    public function kind(): Kind
    {
        return Kind::Customer;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(ShopifyNode $node): Desired
    {
        $default = $node->child('defaultAddress');
        $defaultId = $default?->id();
        $extra = [];
        foreach ($node->list('addressesV2') as $address) {
            if ($address->id() !== $defaultId) {
                $extra[] = Fields::address($address);
            }
        }
        $email = $node->child('defaultEmailAddress')?->optionalString('emailAddress');
        $emailConsent = $node->child('emailMarketingConsent');
        $smsConsent = $node->child('smsMarketingConsent');

        return new Desired(Kind::Customer, $node->id(), [
            'email' => $email === null ? null : strtolower($email),
            'username' => self::USERNAME_PREFIX . $node->string('legacyResourceId'),
            'first_name' => $node->optionalString('firstName'),
            'last_name' => $node->optionalString('lastName'),
            'phone' => $node->child('defaultPhoneNumber')?->optionalString('phoneNumber'),
            'locale' => $node->optionalString('locale'),
            'note' => $node->optionalString('note'),
            'tags' => $node->strings('tags'),
            'state' => $node->string('state'),
            'tax_exempt' => $node->bool('taxExempt'),
            'tax_exemptions' => $node->strings('taxExemptions'),
            'verified_email' => $node->bool('verifiedEmail'),
            'created_at' => $node->string('createdAt'),
            'email_marketing' => $emailConsent === null ? null : self::consent($emailConsent),
            'sms_marketing' => $smsConsent === null ? null : self::consent($smsConsent),
            'billing' => Fields::address($default),
            'shipping' => Fields::address($default),
            'extra_addresses' => $extra,
            'meta' => Fields::metafields($node),
        ]);
    }

    /** @return array{state: string, opt_in_level: ?string, updated_at: ?string} */
    private static function consent(ShopifyNode $consent): array
    {
        return [
            'state' => $consent->string('marketingState'),
            'opt_in_level' => $consent->optionalString('marketingOptInLevel'),
            'updated_at' => $consent->optionalString('consentUpdatedAt'),
        ];
    }
}
