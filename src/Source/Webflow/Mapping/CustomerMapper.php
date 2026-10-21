<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A customer → a WooCommerce customer. Webflow keeps no customer object for e-commerce buyers, so the reader
 * gathers each buyer from their orders (by email): the name and addresses of their first order, dated when it
 * was placed. The name is the first name (Webflow doesn't split it). Order counts aren't mirrored (they change
 * with every sale).
 */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'webflow-';

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
        $billing = Fields::address($node->child('billingAddress'));
        $shipping = Fields::address($node->child('shippingAddress'));

        return new Desired(Kind::Customer, $node->id(), [
            'email' => strtolower($node->string('email')),
            'username' => self::USERNAME_PREFIX . Fields::sourceId($node),
            'first_name' => $node->optionalString('fullName'),
            'last_name' => null,
            'phone' => null,
            'locale' => null,
            'note' => null,
            'created_at' => Fields::time($node, 'firstOrderOn'),
            'billing' => $billing === [] ? null : $billing,
            'shipping' => $shipping === [] ? null : $shipping,
        ]);
    }
}
