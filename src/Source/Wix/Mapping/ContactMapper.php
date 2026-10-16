<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A contact → a WooCommerce customer: name, email, phone, locale and creation time; the address tagged
 * billing (else the default, else the first) as the billing address and the one tagged shipping (else the
 * default, else the first) as the shipping address. Every saved address, the company, additional emails
 * and phones, birthday, time zone, member details, marketing consent, segments and source are mirrored as
 * meta. Activity and deliverability (which change on their own) are not.
 */
final class ContactMapper implements Mapper
{
    public const USERNAME_PREFIX = 'wix-';

    private const VERSION = 1;
    private const BILLING = 'BILLING';
    private const SHIPPING = 'SHIPPING';
    private const DETAILS = [
        'externalId', 'company', 'additionalEmails', 'additionalPhones', 'birthdate', 'timeZone', 'assigneeId', 'locationIds',
        'source', 'segmentIds',
    ];
    /** A member's details, without their login sessions (which change on every login). */
    private const MEMBER_DETAILS = ['memberId', 'signupDate', 'email', 'emailVerified', 'status', 'profileInfo', 'userInfo', 'groupInfo'];

    public function kind(): Kind
    {
        return Kind::Customer;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $email = $node->child('email');
        $addresses = $node->list('addresses');
        $billing = self::tagged($addresses, self::BILLING);
        $shipping = self::tagged($addresses, self::SHIPPING);
        $billingAddress = $billing === null ? [] : Fields::contactAddress($billing, $node);
        $shippingAddress = $shipping === null ? [] : Fields::contactAddress($shipping, $node);
        $address = $email?->optionalString('email');

        return new Desired(Kind::Customer, $node->id(), [
            'email' => $address === null ? null : strtolower($address),
            'username' => self::USERNAME_PREFIX . Fields::sourceId($node),
            'first_name' => $node->child('name')?->optionalString('first'),
            'last_name' => $node->child('name')?->optionalString('last'),
            'phone' => $node->child('phone')?->optionalString('phone'),
            'locale' => $node->optionalString('locale'),
            'note' => null,
            'created_at' => Fields::time($node, 'createdDate'),
            'billing' => $billingAddress === [] ? null : $billingAddress,
            'shipping' => $shippingAddress === [] ? null : $shippingAddress,
            'addresses' => array_map(static fn(Node $saved): array => [
                'id' => $saved->optionalString('id'),
                'tag' => $saved->optionalString('tag'),
                'default' => ($saved->raw()['defaultAddress'] ?? false) === true,
                ...Fields::contactAddress($saved, $node),
            ], $addresses),
            'email_subscription' => $email?->child('subscriptionStatus')?->optionalString('status'),
            'phone_subscription' => $node->child('phone')?->child('subscriptionStatus')?->optionalString('status'),
            'member' => $node->child('memberInfo')?->only(self::MEMBER_DETAILS),
            'customer' => $node->only(self::DETAILS),
        ]);
    }

    /** @param list<Node> $addresses the address with $tag, else the default one, else the first */
    private static function tagged(array $addresses, string $tag): ?Node
    {
        foreach ($addresses as $address) {
            if ($address->optionalString('tag') === $tag) {
                return $address;
            }
        }
        foreach ($addresses as $address) {
            if (($address->raw()['defaultAddress'] ?? false) === true) {
                return $address;
            }
        }

        return $addresses[0] ?? null;
    }
}
