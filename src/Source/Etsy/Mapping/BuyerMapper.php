<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * An Etsy buyer → a WooCommerce customer. Etsy exposes only the buyer's user id and email (a receipt's
 * name and address are the recipient's, often someone else), so that is all the customer holds; orders
 * keep their own addresses.
 */
final class BuyerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'etsy-';

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
        $email = $node->optionalString('buyer_email');

        return new Desired(Kind::Customer, $node->id(), [
            'email' => $email === null ? null : strtolower($email),
            'username' => self::USERNAME_PREFIX . $node->int('buyer_user_id'),
            'first_name' => null,
            'last_name' => null,
            'phone' => null,
            'locale' => null,
            'note' => null,
            'created_at' => null,
            'billing' => null,
            'shipping' => null,
        ]);
    }
}
