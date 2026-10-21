<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * An account → a FluentCRM company: name, phone, website, industry, account type, description, employees,
 * billing address (else shipping address), created time, and the org's custom account fields as FluentCRM
 * company custom fields. Every other field a user can set (rating, revenue, ownership, parent account, owner,
 * the shipping address) is meta.
 */
final class AccountMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = [
        'Account_Name', 'Phone', 'Website', 'Industry', 'Account_Type', 'Description', 'Employees', 'Billing_Street',
        'Billing_City', 'Billing_State', 'Billing_Code', 'Billing_Country', 'Created_Time',
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
        $employees = Fields::text($node, 'Employees');

        return new Desired(Kind::CrmCompany, $node->id(), [
            'name' => Fields::text($node, 'Account_Name') ?? 'Account ' . Fields::sourceId($node),
            'email' => null,
            'phone' => Fields::text($node, 'Phone'),
            'website' => Fields::text($node, 'Website'),
            'industry' => Fields::text($node, 'Industry'),
            'type' => Fields::text($node, 'Account_Type'),
            'description' => Fields::text($node, 'Description'),
            'timezone' => null,
            'logo' => null,
            'linkedin_url' => null,
            'facebook_url' => null,
            'twitter_url' => null,
            'employees_number' => $employees === null ? null : (int) $employees,
            'address' => Fields::crmAddress($node, 'Billing_', 'Billing_Code', 'Billing_Country') ?? Fields::crmAddress($node, 'Shipping_', 'Shipping_Code', 'Shipping_Country'),
            'created_at' => Fields::time($node, 'Created_Time'),
            'custom_fields' => Fields::customFields($node),
            'custom_group' => Fields::SOURCE_LABEL,
            'account' => Fields::details($node, self::NATIVE),
        ]);
    }
}
