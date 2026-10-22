<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\QuickBooksOnline\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A customer → a WooCommerce customer, so its invoices and sales receipts are theirs: email, given and family name
 * (else the display name), phone (else mobile), notes, created time, billing address (with the company name) and
 * shipping address. WooCommerce customers need an email address, so a customer without one is not mirrored here;
 * its sales are guest orders in its name and address. Its display name, title, suffix, other phones, website,
 * parent customer (a sub-customer or job), balance, currency, terms, tax details and active flag are meta. The same
 * customer is a FluentCRM contact (ContactMapper).
 */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'qbo-';

    private const VERSION = 1;
    private const DETAILS = [
        'DisplayName', 'Title', 'MiddleName', 'Suffix', 'FullyQualifiedName', 'AlternatePhone', 'Fax', 'WebAddr', 'ParentRef', 'Job',
        'Balance', 'CurrencyRef', 'SalesTermRef', 'PaymentMethodRef', 'Taxable', 'DefaultTaxCodeRef', 'ResaleNum', 'PrimaryTaxIdentifier',
        'BusinessNumber', 'PreferredDeliveryMethod', 'Active', 'IsProject',
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
        $email = Fields::email($node, 'PrimaryEmailAddr') ?? throw new Unsupported('the customer has no email address; a WooCommerce customer needs one (its sales are guest orders).');

        return new Desired(Kind::Customer, $node->id(), [
            'email' => $email,
            'username' => self::USERNAME_PREFIX . Fields::sourceId($node),
            'first_name' => self::firstName($node),
            'last_name' => Fields::text($node, 'FamilyName'),
            'phone' => self::phone($node),
            'locale' => null,
            'note' => Fields::text($node, 'Notes'),
            'created_at' => Fields::created($node),
            'billing' => self::address($node, $node->child('BillAddr')),
            'shipping' => self::address($node, $node->child('ShipAddr'), false),
            'customer' => Fields::details($node, self::DETAILS),
        ]);
    }

    /** A customer's first name: its given name, else its display name when it has no family name either. */
    public static function firstName(Node $customer): ?string
    {
        return Fields::text($customer, 'GivenName') ?? (Fields::text($customer, 'FamilyName') === null ? Fields::text($customer, 'DisplayName') : null);
    }

    public static function phone(Node $customer): ?string
    {
        return Fields::phone($customer, 'PrimaryPhone') ?? Fields::phone($customer, 'Mobile');
    }

    /** @return array<string, string>|null a customer's name, company and an address of theirs */
    public static function address(Node $customer, ?Node $address, bool $withPhone = true): ?array
    {
        $parts = Fields::storeAddress($address);
        if ($parts === [] && $address === null && !$withPhone) {
            return null;
        }
        $result = array_filter([
            'first_name' => self::firstName($customer),
            'last_name' => Fields::text($customer, 'FamilyName'),
            'company' => Fields::text($customer, 'CompanyName'),
            ...$parts,
            'phone' => $withPhone ? self::phone($customer) : null,
        ], static fn(?string $value): bool => $value !== null);

        return $result === [] ? null : $result;
    }
}
