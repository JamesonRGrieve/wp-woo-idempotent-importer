<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\FreshBooks\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A client organization → a FluentCRM company. FreshBooks keeps the organization as a client's business name, not
 * a record of its own, so the reader gathers the clients that name the same organization (`client_ids`); the
 * company is its name, with the first such client's phone and billing address.
 */
final class OrganizationMapper implements Mapper
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
            'name' => $node->string('organization'),
            'email' => null,
            'phone' => Fields::phone($node),
            'website' => null,
            'industry' => Fields::text($node, 'company_industry'),
            'type' => null,
            'description' => null,
            'timezone' => null,
            'logo' => null,
            'linkedin_url' => null,
            'facebook_url' => null,
            'twitter_url' => null,
            'employees_number' => null,
            'address' => Fields::crmAddress($node),
            'created_at' => null,
            'custom_fields' => [],
            'custom_group' => Fields::SOURCE_LABEL,
            'organization' => Fields::details($node, ['company_size', 'vat_name', 'vat_number']),
        ]);
    }
}
