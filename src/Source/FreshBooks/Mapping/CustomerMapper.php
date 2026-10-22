<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\FreshBooks\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A client → a WooCommerce customer, so its invoices are theirs: email, first and last name, phone (business,
 * else mobile, else home), billing address (with the organization as its company). The same client is also a
 * FluentCRM contact (ContactMapper). Its fax, VAT name and number, currency, language (a two-letter code, not a
 * WordPress locale), sign-up time, note and accounting id are meta. WooCommerce customers need an email address, so a
 * client without one is not mirrored here; its invoices are guest orders with its name and address.
 */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'freshbooks-';

    private const VERSION = 1;
    private const DETAILS = ['fax', 'vat_name', 'vat_number', 'currency_code', 'signup_date', 'accounting_systemid', 'note', 'language'];

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
        $email = Fields::text($node, 'email') ?? throw new Unsupported('the client has no email address; a WooCommerce customer needs one (its invoices are guest orders).');
        $address = Fields::storeAddress($node);

        return new Desired(Kind::Customer, $node->id(), [
            'email' => strtolower($email),
            'username' => self::USERNAME_PREFIX . Fields::sourceId($node),
            'first_name' => Fields::text($node, 'fname'),
            'last_name' => Fields::text($node, 'lname'),
            'phone' => Fields::phone($node),
            'locale' => null,
            'note' => null,
            'created_at' => null,
            'billing' => $address === [] && Fields::text($node, 'organization') === null ? null : array_filter([
                'first_name' => Fields::text($node, 'fname'),
                'last_name' => Fields::text($node, 'lname'),
                'company' => Fields::text($node, 'organization'),
                ...$address,
                'phone' => Fields::phone($node),
            ], static fn(?string $value): bool => $value !== null),
            'shipping' => null,
            'customer' => Fields::details($node, self::DETAILS),
        ]);
    }
}
