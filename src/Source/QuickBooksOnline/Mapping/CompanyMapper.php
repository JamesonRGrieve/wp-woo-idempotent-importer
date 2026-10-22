<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\QuickBooksOnline\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A customer with a company name → a FluentCRM company: the company name, phone, website, billing address and
 * created time. The customer itself is the FluentCRM contact (ContactMapper), linked to it.
 */
final class CompanyMapper implements Mapper
{
    private const VERSION = 1;

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
        return new Desired(Kind::CrmCompany, $node->id(), [
            'name' => $node->string('CompanyName'),
            'email' => null,
            'phone' => CustomerMapper::phone($node),
            'website' => $node->child('WebAddr')?->optionalString('URI'),
            'industry' => null,
            'type' => null,
            'description' => null,
            'timezone' => null,
            'logo' => null,
            'linkedin_url' => null,
            'facebook_url' => null,
            'twitter_url' => null,
            'employees_number' => null,
            'address' => Fields::crmAddress($node->child('BillAddr')),
            'created_at' => Fields::created($node),
            'custom_fields' => [],
            'custom_group' => Fields::SOURCE_LABEL,
            'company' => Fields::details($node, ['BusinessNumber', 'PrimaryTaxIdentifier', 'ResaleNum']),
        ]);
    }
}
