<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\HubSpot\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A contact → a FluentCRM contact: email, first and last name, phone (else mobile phone), address, created
 * date, its companies (the first associated is the primary one) and the lists it belongs to, and the account's
 * own contact properties as FluentCRM custom fields. A contact whose lifecycle stage is `customer` is a
 * FluentCRM customer, the rest are leads. HubSpot's email consent lives in its subscription types, which this
 * read doesn't include, so contacts are transactional (FluentCRM sends them no marketing). Every other HubSpot
 * property (job title, lead status, timezone, owner, analytics) is meta. FluentCRM keeps one contact per email,
 * so a contact without an email address is not mirrored.
 */
final class ContactMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = ['email', 'firstname', 'lastname', 'phone', 'address', 'city', 'state', 'zip', 'country', 'createdate', 'lifecyclestage'];
    private const CUSTOMER_STAGE = 'customer';

    public function kind(): Kind
    {
        return Kind::Contact;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $email = Fields::property($node, 'email') ?? throw new Unsupported('the contact has no email address; FluentCRM keeps contacts by email.');

        return new Desired(Kind::Contact, $node->id(), [
            'email' => strtolower($email),
            'prefix' => null,
            'first_name' => Fields::property($node, 'firstname'),
            'last_name' => Fields::property($node, 'lastname'),
            'phone' => Fields::property($node, 'phone') ?? Fields::property($node, 'mobilephone'),
            'timezone' => null,
            'date_of_birth' => null,
            'address' => CrmContract::address([
                'address_line_1' => Fields::property($node, 'address'),
                'city' => Fields::property($node, 'city'),
                'state' => Fields::property($node, 'state'),
                'postal_code' => Fields::property($node, 'zip'),
                'country' => Fields::property($node, 'country'),
            ]),
            'status' => CrmContract::status(false, false),
            'contact_type' => Fields::property($node, 'lifecyclestage') === self::CUSTOMER_STAGE ? CrmContract::CUSTOMER : CrmContract::LEAD,
            'source' => Fields::SOURCE_LABEL,
            'created_at' => Fields::time($node, 'createdate'),
            'company_gids' => array_map(static fn(string $id): string => Fields::id('Company', $id), Fields::associated($node, 'companies')),
            'list_gids' => array_map(static fn(string $id): string => Fields::id('List', $id), $node->strings('list_ids')),
            'tag_gids' => [],
            'custom_fields' => Fields::customFields($node),
            'custom_group' => Fields::SOURCE_LABEL,
            'contact' => Fields::details($node, self::NATIVE),
        ]);
    }
}
