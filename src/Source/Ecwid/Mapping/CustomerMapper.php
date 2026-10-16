<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Ecwid\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A customer → a WooCommerce customer: email, the billing person's name (first and last when Ecwid splits it,
 * else whole as the first name), phone (the billing phone, else the default phone contact), the store
 * owner's private notes as the note, registration time, the billing address and the first saved shipping
 * address. The language (a bare code, not a WordPress locale), customer group, tax id and exemption,
 * marketing consent, every contact and every saved address are mirrored as meta. Order statistics and
 * favorites are volatile and not mirrored.
 */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'ecwid-';

    private const VERSION = 1;
    private const PHONE = 'PHONE';
    private const DETAILS = [
        'lang', 'customerGroupId', 'customerGroupName', 'taxId', 'taxIdValid', 'taxExempt', 'acceptMarketing', 'contacts',
        'shippingAddresses',
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
        $email = $node->optionalString('email');
        $person = $node->child('billingPerson');
        $billing = Fields::address($person);
        $addresses = $node->list('shippingAddresses');
        $shipping = $addresses === [] ? [] : Fields::address($addresses[0]);

        return new Desired(Kind::Customer, $node->id(), [
            'email' => $email === null ? null : strtolower($email),
            'username' => self::USERNAME_PREFIX . Fields::sourceId($node),
            'first_name' => $billing['first_name'] ?? null,
            'last_name' => $billing['last_name'] ?? null,
            'phone' => $person?->optionalString('phone') ?? self::phoneContact($node),
            'locale' => null,
            'note' => $node->optionalString('privateAdminNotes'),
            'created_at' => Fields::time($node, 'registered'),
            'billing' => Fields::hasStreet($billing) ? $billing : null,
            'shipping' => Fields::hasStreet($shipping) ? $shipping : null,
            'customer' => $node->only(self::DETAILS),
        ]);
    }

    /** The default phone contact, else the first one. */
    private static function phoneContact(Node $customer): ?string
    {
        $phones = array_values(array_filter($customer->list('contacts'), static fn(Node $contact): bool => $contact->optionalString('type') === self::PHONE));
        usort($phones, static fn(Node $a, Node $b): int => (($b->raw()['default'] ?? false) === true) <=> (($a->raw()['default'] ?? false) === true));

        return ($phones[0] ?? null)?->optionalString('contact');
    }
}
