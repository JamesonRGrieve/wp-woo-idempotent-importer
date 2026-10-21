<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A contact a sales order names (the reader reads only those) → a WooCommerce customer, so the orders are theirs:
 * email, first and last name, phone, created time and mailing address as the billing address. The same contact
 * is also a FluentCRM contact (ContactMapper).
 */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'zoho-';

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
        $email = Fields::text($node, 'Email');
        $address = Fields::storeAddress($node, 'Mailing_', 'Mailing_Zip', 'Mailing_Country');

        return new Desired(Kind::Customer, $node->id(), [
            'email' => $email === null ? null : strtolower($email),
            'username' => self::USERNAME_PREFIX . Fields::sourceId($node),
            'first_name' => Fields::text($node, 'First_Name'),
            'last_name' => Fields::text($node, 'Last_Name'),
            'phone' => Fields::text($node, 'Phone') ?? Fields::text($node, 'Mobile'),
            'locale' => null,
            'note' => null,
            'created_at' => Fields::time($node, 'Created_Time'),
            'billing' => $address === [] ? null : array_filter([
                'first_name' => Fields::text($node, 'First_Name'),
                'last_name' => Fields::text($node, 'Last_Name'),
                ...$address,
                'phone' => Fields::text($node, 'Phone'),
            ], static fn(?string $value): bool => $value !== null),
            'shipping' => null,
        ]);
    }
}
