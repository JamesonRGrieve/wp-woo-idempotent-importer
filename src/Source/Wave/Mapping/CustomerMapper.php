<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wave\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\PlaceholderEmail;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Wave\WaveAdapter;

/**
 * A customer → a WooCommerce customer, so its invoices are theirs: email, first and last name (else its name), phone
 * (else mobile), created time, address as the billing address (with a business's name as the company), shipping
 * details as the shipping address. WooCommerce customers need an email address, so a customer without one gets a
 * placeholder under `.invalid` (PlaceholderEmail), which no mail is ever sent to. Its account number, fax, toll-free number,
 * website, internal notes, currency and archived flag are meta. The same customer is a FluentCRM contact
 * (ContactMapper).
 */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'wave-';

    private const VERSION = 2;
    private const DETAILS = ['displayId', 'fax', 'tollFree', 'website', 'internalNotes', 'currency', 'isArchived', 'modifiedAt', 'wave_id'];

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

        return new Desired(Kind::Customer, $node->id(), [
            ...PlaceholderEmail::fields($email, WaveAdapter::ID, 'customer', Fields::recordId($node->string('wave_id'))),
            'username' => self::USERNAME_PREFIX . Fields::recordId($node->string('wave_id')),
            'first_name' => self::firstName($node),
            'last_name' => Fields::text($node, 'lastName'),
            'phone' => Fields::text($node, 'phone') ?? Fields::text($node, 'mobile'),
            'locale' => null,
            'note' => null,
            'created_at' => Fields::time($node, 'createdAt'),
            'billing' => self::billing($node),
            'shipping' => self::shipping($node->child('shippingDetails')),
            'customer' => Fields::details($node, self::DETAILS),
        ]);
    }

    /** A customer's first name: its contact person's, else its name when that is the person. */
    public static function firstName(Node $customer): ?string
    {
        return Fields::text($customer, 'firstName') ?? (Fields::text($customer, 'lastName') === null ? Fields::text($customer, 'name') : null);
    }

    /** @return array<string, string>|null a customer's name and address, as an order's or a customer's billing address */
    public static function billing(Node $customer): ?array
    {
        $billing = array_filter([
            'first_name' => self::firstName($customer),
            'last_name' => Fields::text($customer, 'lastName'),
            'company' => Fields::companyName($customer),
            ...Fields::storeAddress($customer->child('address')),
            'phone' => Fields::text($customer, 'phone') ?? Fields::text($customer, 'mobile'),
        ], static fn(?string $value): bool => $value !== null);

        return $billing === [] ? null : $billing;
    }

    /** @return array<string, string>|null */
    private static function shipping(?Node $details): ?array
    {
        if ($details === null) {
            return null;
        }
        $shipping = array_filter([
            'first_name' => Fields::text($details, 'name'),
            ...Fields::storeAddress($details->child('address')),
            'phone' => Fields::text($details, 'phone'),
        ], static fn(?string $value): bool => $value !== null);

        return $shipping === [] ? null : $shipping;
    }
}
