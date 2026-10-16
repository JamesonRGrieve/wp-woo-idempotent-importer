<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\SquareOnline\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A customer profile → a WooCommerce customer: name, email, phone, note, creation time, and its one address
 * as both billing and shipping address (with the company). Nickname, birthday, reference id, groups,
 * segments, tax ids, email preference and creation source are mirrored as meta.
 */
final class CustomerMapper implements Mapper
{
    public const USERNAME_PREFIX = 'square-';

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
        $email = $node->optionalString('email_address');
        $firstName = $node->optionalString('given_name');
        $lastName = $node->optionalString('family_name');
        $phone = $node->optionalString('phone_number');
        $address = Fields::address($node->child('address'), $firstName, $lastName, $node->optionalString('company_name'), $phone);

        return new Desired(Kind::Customer, $node->id(), [
            'email' => $email === null ? null : strtolower($email),
            'username' => self::USERNAME_PREFIX . Fields::squareId($node),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'locale' => null,
            'note' => $node->optionalString('note'),
            'created_at' => Fields::time($node, 'created_at'),
            'billing' => $address === [] ? null : $address,
            'shipping' => $address === [] ? null : $address,
            'nickname' => $node->optionalString('nickname'),
            'birthday' => $node->optionalString('birthday'),
            'reference_id' => $node->optionalString('reference_id'),
            'group_ids' => $node->strings('group_ids'),
            'segment_ids' => $node->strings('segment_ids'),
            'tax_ids' => $node->raw()['tax_ids'] ?? null,
            'email_unsubscribed' => $node->child('preferences')?->raw()['email_unsubscribed'] ?? null,
            'creation_source' => $node->optionalString('creation_source'),
        ]);
    }
}
