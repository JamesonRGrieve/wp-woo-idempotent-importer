<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * An account → a FluentCRM company: name, phone, website, industry, type, description, number of employees,
 * billing address (else shipping address), created date, and the org's custom account fields as FluentCRM
 * company custom fields. Every other field a user can set (rating, revenue, ownership, parent account, owner,
 * the shipping address) is meta.
 */
final class AccountMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = [
        'Name', 'Phone', 'Website', 'Industry', 'Type', 'Description', 'NumberOfEmployees', 'BillingStreet', 'BillingCity',
        'BillingState', 'BillingPostalCode', 'BillingCountry', 'CreatedDate',
    ];

    public function kind(): Kind
    {
        return Kind::CrmCompany;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $employees = Fields::text($node, 'NumberOfEmployees');

        return new Desired(Kind::CrmCompany, $node->id(), [
            'name' => $node->string('Name'),
            'email' => null,
            'phone' => Fields::text($node, 'Phone'),
            'website' => Fields::text($node, 'Website'),
            'industry' => Fields::text($node, 'Industry'),
            'type' => Fields::text($node, 'Type'),
            'description' => Fields::text($node, 'Description'),
            'timezone' => null,
            'logo' => null,
            'linkedin_url' => null,
            'facebook_url' => null,
            'twitter_url' => null,
            'employees_number' => $employees === null ? null : (int) $employees,
            'address' => Fields::crmAddress($node, 'Billing') ?? Fields::crmAddress($node, 'Shipping'),
            'created_at' => Fields::time($node, 'CreatedDate'),
            'custom_fields' => Fields::customFields($node),
            'custom_group' => Fields::SOURCE_LABEL,
            'account' => Fields::details($node, self::NATIVE),
        ]);
    }
}
