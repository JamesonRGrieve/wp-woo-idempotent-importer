<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * An organization → a FluentCRM company: name, website, LinkedIn profile, number of employees, address, created
 * date, and the account's own organization fields as FluentCRM company custom fields. Its industry (an option of
 * the industry field) by name, annual revenue, labels, owner and visibility are meta.
 */
final class OrganizationMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['annual_revenue', 'label_ids', 'owner_id', 'visible_to'];

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
            'name' => $node->string('name'),
            'email' => null,
            'phone' => null,
            'website' => $node->optionalString('website'),
            'industry' => $node->optionalString('industry_label'),
            'type' => null,
            'description' => null,
            'timezone' => null,
            'logo' => null,
            'linkedin_url' => $node->optionalString('linkedin'),
            'facebook_url' => null,
            'twitter_url' => null,
            'employees_number' => $node->optionalInt('employee_count'),
            'address' => Fields::crmAddress($node->child('address')),
            'created_at' => Fields::time($node, 'add_time'),
            'custom_fields' => Fields::customFields($node),
            'custom_group' => Fields::SOURCE_LABEL,
            'organization' => array_filter($node->only(self::DETAILS), static fn(mixed $value): bool => $value !== null && $value !== []),
        ]);
    }
}
