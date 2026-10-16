<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Magento\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A customer account → a WooCommerce customer: name, email, creation time, and the default billing and
 * shipping addresses (the billing address's phone is the customer's phone). Every saved address, the customer
 * group, website and store view, date of birth, gender, tax/VAT number, newsletter subscription and other
 * attributes are mirrored as meta. Magento never exposes passwords; customers reset theirs at cutover.
 */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'magento-';

    private const VERSION = 1;
    private const DETAILS = [
        'group_id', 'website_id', 'store_id', 'created_in', 'updated_at', 'middlename', 'prefix', 'suffix', 'dob',
        'gender', 'taxvat', 'disable_auto_group_change', 'custom_attributes',
    ];

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
        $addresses = $node->list('addresses');
        $billing = Fields::address(self::defaultAddress($addresses, $node->optionalString('default_billing'), 'default_billing'));
        $shipping = Fields::address(self::defaultAddress($addresses, $node->optionalString('default_shipping'), 'default_shipping'));
        $extension = $node->child('extension_attributes');

        return new Desired(Kind::Customer, $node->id(), [
            'email' => strtolower($node->string('email')),
            'username' => self::USERNAME_PREFIX . Fields::sourceId($node),
            'first_name' => $node->optionalString('firstname'),
            'last_name' => $node->optionalString('lastname'),
            'phone' => $billing['phone'] ?? $shipping['phone'] ?? null,
            'locale' => null,
            'note' => null,
            'created_at' => Fields::time($node, 'created_at'),
            'billing' => $billing === [] ? null : $billing,
            'shipping' => $shipping === [] ? null : $shipping,
            'addresses' => array_map(static fn(Node $address): array => [
                'id' => $address->int('id'),
                'default_billing' => ($address->raw()['default_billing'] ?? false) === true,
                'default_shipping' => ($address->raw()['default_shipping'] ?? false) === true,
                ...Fields::address($address),
            ], $addresses),
            'is_subscribed' => $extension?->raw()['is_subscribed'] ?? null,
            'customer' => Fields::details($node, self::DETAILS),
        ]);
    }

    /**
     * The customer's default address of a kind: the one its id names, else the one flagged as the default.
     *
     * @param list<Node> $addresses
     */
    private static function defaultAddress(array $addresses, ?string $defaultId, string $flag): ?Node
    {
        foreach ($addresses as $address) {
            if ($defaultId !== null && (string) $address->int('id') === $defaultId) {
                return $address;
            }
        }
        foreach ($addresses as $address) {
            if (($address->raw()[$flag] ?? false) === true) {
                return $address;
            }
        }

        return null;
    }
}
