<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A customer → a WooCommerce customer: name, email, phone, notes, creation time, and the first saved address
 * as both billing and shipping address (WooCommerce keeps one of each). Every saved address, the customer
 * group, tax-exempt category, store credit, attributes, form fields, channels and marketing consent are
 * mirrored as meta.
 */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'bigcommerce-';

    private const VERSION = 1;
    private const DETAILS = [
        'company', 'customer_group_id', 'tax_exempt_category', 'registration_ip_address', 'store_credit_amounts',
        'attributes', 'form_fields', 'accepts_product_review_abandoned_cart_emails', 'origin_channel_id', 'channel_ids',
        'authentication',
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
        $addresses = $node->list('addresses');
        $phone = $node->optionalString('phone');
        $address = Fields::customerAddress($addresses[0] ?? null);
        if ($address !== [] && !isset($address['phone']) && $phone !== null) {
            $address['phone'] = $phone;
        }

        return new Desired(Kind::Customer, $node->id(), [
            'email' => $email === null ? null : strtolower($email),
            'username' => self::USERNAME_PREFIX . Fields::sourceId($node),
            'first_name' => $node->optionalString('first_name'),
            'last_name' => $node->optionalString('last_name'),
            'phone' => $phone,
            'locale' => null,
            'note' => $node->optionalString('notes'),
            'created_at' => Fields::time($node, 'date_created'),
            'billing' => $address === [] ? null : $address,
            'shipping' => $address === [] ? null : $address,
            'addresses' => array_map(static fn(Node $saved): array => [
                'id' => $saved->int('id'),
                'type' => $saved->optionalString('address_type'),
                ...Fields::customerAddress($saved),
            ], $addresses),
            'customer' => Fields::details($node, self::DETAILS),
        ]);
    }
}
