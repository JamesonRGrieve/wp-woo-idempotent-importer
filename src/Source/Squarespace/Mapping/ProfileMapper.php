<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Squarespace\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A profile that is a customer (it has orders) or has an account → a WooCommerce customer: name, email,
 * creation time, and its address (with its phone) as both billing and shipping address. A profile that is
 * only a mailing-list subscriber or donor maps to nothing. Marketing consent and the customer and account
 * flags are mirrored as meta; the transactions summary (order counts and totals) is not, so an order
 * doesn't rewrite its customer.
 */
final class ProfileMapper implements Mapper
{
    public const USERNAME_PREFIX = 'squarespace-';

    private const VERSION = 1;
    private const DETAILS = ['acceptsMarketing', 'isCustomer', 'hasAccount'];

    public function kind(): Kind
    {
        return Kind::Customer;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    /** Whether a profile is mirrored as a customer: it has orders, or an account on the site. */
    public static function isCustomer(Node $profile): bool
    {
        $raw = $profile->raw();

        return ($raw['isCustomer'] ?? false) === true || ($raw['hasAccount'] ?? false) === true;
    }

    public function map(Node $node): ?Desired
    {
        if (!self::isCustomer($node)) {
            return null;
        }
        $email = $node->optionalString('email');
        $addressNode = $node->child('address');
        $address = Fields::address($addressNode);

        return new Desired(Kind::Customer, $node->id(), [
            'email' => $email === null ? null : strtolower($email),
            'username' => self::USERNAME_PREFIX . Fields::sourceId($node),
            'first_name' => $node->optionalString('firstName'),
            'last_name' => $node->optionalString('lastName'),
            'phone' => $addressNode?->optionalString('phone'),
            'locale' => null,
            'note' => null,
            'created_at' => Fields::time($node, 'createdOn'),
            'billing' => $address === [] ? null : $address,
            'shipping' => $address === [] ? null : $address,
            'profile' => $node->only(self::DETAILS),
        ]);
    }
}
