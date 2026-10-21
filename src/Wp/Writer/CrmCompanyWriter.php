<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use FluentCrm\App\Models\Company;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\Fluent\CustomFields;
use IdempotentImporter\Wp\Fluent\FluentCrm;
use IdempotentImporter\Wp\Fluent\FluentMeta;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Retirement;

/**
 * CRM companies (accounts, organizations) → FluentCRM companies: name, email, phone, website, industry, type,
 * description, number of employees, address, timezone, logo and social profiles, and the source's custom
 * company fields. FluentCRM shows companies once its Companies module is switched on (FluentCRM → Settings →
 * Experimental Features); they are written either way. FluentCRM has no trash: a retired company is marked.
 */
final class CrmCompanyWriter implements Writer
{
    private const ATTRIBUTES = [
        'name', 'email', 'phone', 'website', 'industry', 'type', 'description', 'timezone', 'logo', 'linkedin_url', 'facebook_url', 'twitter_url',
    ];
    private const ADDRESS = ['address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country'];
    private const META_CUSTOM_VALUES = 'custom_values';
    private const CONSUMED = [...self::ATTRIBUTES, 'employees_number', 'address', 'created_at', 'custom_fields', 'custom_group'];

    public function kind(): Kind
    {
        return Kind::CrmCompany;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        FluentCrm::require();
        $f = Values::of($desired);
        $company = ($existing === null ? null : FluentCrm::company($existing->targetId)) ?? new Company();
        $attributes = [];
        foreach (self::ATTRIBUTES as $name) {
            $attributes[$name] = $f->nullableString($name);
        }
        $attributes['employees_number'] = $f->nullableInt('employees_number') ?? 0;
        $address = $f->record('address');
        foreach (self::ADDRESS as $name) {
            $attributes[$name] = $address?->nullableString($name);
        }
        $createdAt = $f->nullableString('created_at');
        if (FluentCrm::id($company) === 0 && $createdAt !== null) {
            $attributes['created_at'] = FluentCrm::localTime($createdAt);
        }
        FluentCrm::fill($company, $attributes);
        $values = CustomFields::define(true, $f->string('custom_group'), $f->records('custom_fields'));
        $meta = $company->getAttribute('meta');
        $meta = is_array($meta) ? $meta : [];
        $current = is_array($meta[self::META_CUSTOM_VALUES] ?? null) ? $meta[self::META_CUSTOM_VALUES] : [];
        $custom = CustomFields::companyValues($current, $values);
        if ($custom !== $current || !array_key_exists(self::META_CUSTOM_VALUES, $meta)) {
            $company->fill(['meta' => [...$meta, self::META_CUSTOM_VALUES => $custom]]);
            $company->save();
        }
        $id = FluentCrm::id($company);
        MetaSync::mirror(new FluentMeta(Kind::CrmCompany, $id), $desired->gid, $f, self::CONSUMED);

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return defined('FLUENTCRM') && FluentCrm::company($targetId) !== null;
    }

    public function retire(Link $link): void
    {
        Retirement::mark(new FluentMeta(Kind::CrmCompany, $link->targetId));
    }
}
