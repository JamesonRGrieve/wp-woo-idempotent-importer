<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\HubSpot\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A company → a FluentCRM company: name (else its domain), domain as the website, phone, industry, city and
 * state, created date, and the account's own company properties as FluentCRM company custom fields. Every other
 * property the account can set (address, employees, revenue, lifecycle stage, owner) is meta.
 */
final class CompanyMapper implements Mapper
{
    private const VERSION = 1;
    private const NATIVE = ['name', 'domain', 'phone', 'industry', 'city', 'state', 'createdate'];

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
        $domain = Fields::property($node, 'domain');

        return new Desired(Kind::CrmCompany, $node->id(), [
            'name' => Fields::property($node, 'name') ?? $domain ?? 'Company ' . Fields::sourceId($node),
            'email' => null,
            'phone' => Fields::property($node, 'phone'),
            'website' => $domain === null ? null : (str_contains($domain, '://') ? $domain : "https://{$domain}"),
            'industry' => Fields::property($node, 'industry'),
            'type' => null,
            'description' => null,
            'timezone' => null,
            'logo' => null,
            'linkedin_url' => null,
            'facebook_url' => null,
            'twitter_url' => null,
            'employees_number' => null,
            'address' => CrmContract::address(['city' => Fields::property($node, 'city'), 'state' => Fields::property($node, 'state')]),
            'created_at' => Fields::time($node, 'createdate'),
            'custom_fields' => Fields::customFields($node),
            'custom_group' => Fields::SOURCE_LABEL,
            'company' => Fields::details($node, self::NATIVE),
        ]);
    }
}
