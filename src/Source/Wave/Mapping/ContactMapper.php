<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wave\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A customer → a FluentCRM contact: email, first and last name (else its name), phone (else mobile), address,
 * created time, source "Wave", customer contact type, and its business (when it is one) as its company. Wave records
 * no marketing consent, so the status is transactional. Its account number, other phones, website, internal notes,
 * currency, shipping details and archived flag are meta. FluentCRM keeps one contact per email, so a customer
 * without an email address is not mirrored here.
 */
final class ContactMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['name', 'displayId', 'mobile', 'fax', 'tollFree', 'website', 'internalNotes', 'currency', 'shippingDetails', 'isArchived', 'wave_id'];

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
        $email = Fields::text($node, 'email') ?? throw new Unsupported('the customer has no email address; FluentCRM keeps contacts by email.');

        return new Desired(Kind::Contact, $node->id(), [
            'email' => strtolower($email),
            'prefix' => null,
            'first_name' => CustomerMapper::firstName($node),
            'last_name' => Fields::text($node, 'lastName'),
            'phone' => Fields::text($node, 'phone') ?? Fields::text($node, 'mobile'),
            'timezone' => null,
            'date_of_birth' => null,
            'address' => Fields::crmAddress($node->child('address')),
            'status' => CrmContract::status(false, false),
            'contact_type' => CrmContract::CUSTOMER,
            'record_type' => 'Customer',
            'source' => Fields::SOURCE_LABEL,
            'created_at' => Fields::time($node, 'createdAt'),
            'company_gids' => Fields::companyName($node) === null ? [] : [Fields::id('Company', Fields::recordId($node->string('wave_id')))],
            'list_gids' => [],
            'tag_gids' => [],
            'custom_fields' => [],
            'custom_group' => Fields::SOURCE_LABEL,
            'contact' => Fields::details($node, self::DETAILS),
        ]);
    }
}
