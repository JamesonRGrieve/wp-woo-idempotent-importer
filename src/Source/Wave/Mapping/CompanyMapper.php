<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wave\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;

/**
 * A customer that is a business (its name is not its contact person's: Fields::companyName) → a FluentCRM company:
 * the name, phone (else mobile), website, address and created time. Its contact person is the FluentCRM contact
 * (ContactMapper), linked to it.
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
            'name' => Fields::companyName($node) ?? throw new SourceError("Wave customer {$node->id()} is not a business."),
            'email' => null,
            'phone' => Fields::text($node, 'phone') ?? Fields::text($node, 'mobile'),
            'website' => Fields::text($node, 'website'),
            'industry' => null,
            'type' => null,
            'description' => null,
            'timezone' => null,
            'logo' => null,
            'linkedin_url' => null,
            'facebook_url' => null,
            'twitter_url' => null,
            'employees_number' => null,
            'address' => Fields::crmAddress($node->child('address')),
            'created_at' => Fields::time($node, 'createdAt'),
            'custom_fields' => [],
            'custom_group' => Fields::SOURCE_LABEL,
            'company' => Fields::details($node, ['displayId', 'fax', 'tollFree', 'wave_id']),
        ]);
    }
}
