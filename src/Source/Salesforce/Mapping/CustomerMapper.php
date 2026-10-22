<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\PlaceholderEmail;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Salesforce\SalesforceAdapter;

/**
 * A contact an order is billed to (the reader reads only those) → a WooCommerce customer, so the orders are
 * theirs: email, first and last name, phone, created date and mailing address as the billing address. The
 * same contact is also a FluentCRM contact (ContactMapper).
 */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'salesforce-';

    private const VERSION = 2;

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
        $billing = [
            'first_name' => Fields::text($node, 'FirstName'),
            'last_name' => Fields::text($node, 'LastName'),
            ...Fields::storeAddress($node, 'Mailing'),
            'phone' => Fields::text($node, 'Phone'),
        ];

        return new Desired(Kind::Customer, $node->id(), [
            ...PlaceholderEmail::fields($email, SalesforceAdapter::ID, 'record', Fields::sourceId($node)),
            'username' => self::USERNAME_PREFIX . Fields::sourceId($node),
            'first_name' => Fields::text($node, 'FirstName'),
            'last_name' => Fields::text($node, 'LastName'),
            'phone' => Fields::text($node, 'Phone') ?? Fields::text($node, 'MobilePhone'),
            'locale' => null,
            'note' => null,
            'created_at' => Fields::time($node, 'CreatedDate'),
            'billing' => Fields::storeAddress($node, 'Mailing') === [] ? null : array_filter($billing, static fn(?string $value): bool => $value !== null),
            'shipping' => null,
        ]);
    }
}
