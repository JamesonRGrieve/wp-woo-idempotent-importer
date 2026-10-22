<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\QuickBooksOnline\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\PlaceholderEmail;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\QuickBooksOnline\QuickBooksOnlineAdapter;

/**
 * A customer → a FluentCRM contact: email, title, given and family name (else the display name), phone (else
 * mobile), billing address, created time, source "QuickBooks Online", customer contact type, and its company (when
 * it has a company name). QuickBooks records no marketing consent, so the status is transactional. Its display name,
 * other phones, website, notes, shipping address, parent customer, balance and active flag are meta. FluentCRM keeps
 * one contact per email, so a customer without an email address gets a placeholder under `.invalid`
 * (PlaceholderEmail), which no mail is ever sent to.
 */
final class ContactMapper implements Mapper
{
    private const VERSION = 2;
    private const DETAILS = ['DisplayName', 'MiddleName', 'Suffix', 'AlternatePhone', 'Mobile', 'Fax', 'WebAddr', 'Notes', 'ShipAddr', 'ParentRef', 'Balance', 'Active'];

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
        $email = Fields::email($node, 'PrimaryEmailAddr');

        return new Desired(Kind::Contact, $node->id(), [
            ...PlaceholderEmail::fields($email, QuickBooksOnlineAdapter::ID, 'customer', Fields::sourceId($node)),
            'prefix' => Fields::text($node, 'Title'),
            'first_name' => CustomerMapper::firstName($node),
            'last_name' => Fields::text($node, 'FamilyName'),
            'phone' => CustomerMapper::phone($node),
            'timezone' => null,
            'date_of_birth' => null,
            'address' => Fields::crmAddress($node->child('BillAddr')),
            'status' => CrmContract::status(false, false),
            'contact_type' => CrmContract::CUSTOMER,
            'record_type' => 'Customer',
            'source' => Fields::SOURCE_LABEL,
            'created_at' => Fields::created($node),
            'company_gids' => Fields::text($node, 'CompanyName') === null ? [] : [Fields::id('Company', Fields::sourceId($node))],
            'list_gids' => [],
            'tag_gids' => [],
            'custom_fields' => [],
            'custom_group' => Fields::SOURCE_LABEL,
            'contact' => Fields::details($node, self::DETAILS),
        ]);
    }
}
