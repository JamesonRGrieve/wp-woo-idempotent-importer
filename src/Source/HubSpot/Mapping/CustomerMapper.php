<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\HubSpot\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A contact who placed an order (the reader reads only those) → a WooCommerce customer, so their orders are
 * theirs: email, first and last name, phone, created date and address as the billing address. Every contact is
 * also a FluentCRM contact (ContactMapper); this is the store side of the same person.
 */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'hubspot-';

    private const VERSION = 1;

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
        $email = Fields::property($node, 'email');
        $billing = array_filter([
            'first_name' => Fields::property($node, 'firstname'),
            'last_name' => Fields::property($node, 'lastname'),
            'company' => Fields::property($node, 'company'),
            'address_1' => Fields::property($node, 'address'),
            'city' => Fields::property($node, 'city'),
            'state' => Fields::property($node, 'state'),
            'postcode' => Fields::property($node, 'zip'),
            'country' => Fields::property($node, 'country'),
            'phone' => Fields::property($node, 'phone'),
        ], static fn(?string $value): bool => $value !== null);

        return new Desired(Kind::Customer, $node->id(), [
            'email' => $email === null ? null : strtolower($email),
            'username' => self::USERNAME_PREFIX . Fields::sourceId($node),
            'first_name' => Fields::property($node, 'firstname'),
            'last_name' => Fields::property($node, 'lastname'),
            'phone' => Fields::property($node, 'phone') ?? Fields::property($node, 'mobilephone'),
            'locale' => null,
            'note' => null,
            'created_at' => Fields::time($node, 'createdate'),
            'billing' => isset($billing['address_1']) || isset($billing['city']) ? $billing : null,
            'shipping' => null,
        ]);
    }
}
