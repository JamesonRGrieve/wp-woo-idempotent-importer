<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A customer → a WooCommerce customer: name, email, phone (else mobile), memo as the note, language as the
 * locale, creation time, and the billing and shipping addresses. The account type, confirmation, middle name,
 * gender, birth date, company and its numbers, national id and notification choices are mirrored as meta.
 * Login details (last visit, IP, browser) are volatile and not mirrored.
 */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'lightspeed-';

    private const VERSION = 1;
    private const DETAILS = [
        'type', 'isConfirmed', 'middlename', 'gender', 'birthDate', 'nationalId', 'mobile', 'isCompany', 'companyName',
        'companyCoCNumber', 'companyVatNumber', 'doNotifyRegistered', 'doNotifyConfirmed', 'doNotifyPassword',
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
        $email = Fields::text($node, 'email');
        $first = Fields::text($node, 'firstname');
        $last = Fields::text($node, 'lastname');
        $phone = Fields::text($node, 'phone') ?? Fields::text($node, 'mobile');
        $billing = Fields::address($node, 'addressBilling', $first, $last, $phone);
        $shipping = Fields::address($node, 'addressShipping', $first, $last, $phone);

        return new Desired(Kind::Customer, $node->id(), [
            'email' => $email === null ? null : strtolower($email),
            'username' => self::USERNAME_PREFIX . Fields::sourceId($node),
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $phone,
            'locale' => Fields::object($node, 'language')?->optionalString('locale'),
            'note' => Fields::text($node, 'memo'),
            'created_at' => Fields::time($node, 'createdAt'),
            'billing' => self::hasStreet($billing) ? $billing : null,
            'shipping' => self::hasStreet($shipping) ? $shipping : null,
            'customer' => Fields::details($node, self::DETAILS),
        ]);
    }

    /** @param array<string, string> $address */
    private static function hasStreet(array $address): bool
    {
        return isset($address['address_1']) || isset($address['city']) || isset($address['postcode']);
    }
}
