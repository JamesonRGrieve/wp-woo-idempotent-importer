<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\FreshBooks\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A client → a FluentCRM contact: email, first and last name, phone (business, else mobile, else home), billing
 * address, source "FreshBooks", customer contact type, its organization as its company. FreshBooks records no
 * marketing consent, so the status is transactional. Its other phones, fax, shipping address, VAT name and
 * number, currency, language, note, sign-up time and accounting id are meta. FluentCRM keeps one contact per
 * email, so a client without an email address is not mirrored here (it is still a WooCommerce customer).
 */
final class ContactMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = [
        'organization', 'bus_phone', 'mob_phone', 'home_phone', 'fax', 's_street', 's_street2', 's_city', 's_province', 's_code',
        's_country', 'vat_name', 'vat_number', 'currency_code', 'language', 'note', 'signup_date', 'accounting_systemid',
    ];

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
        $email = Fields::text($node, 'email') ?? throw new Unsupported('the client has no email address; FluentCRM keeps contacts by email.');
        $organization = $node->optionalString('organization_gid');

        return new Desired(Kind::Contact, $node->id(), [
            'email' => strtolower($email),
            'prefix' => null,
            'first_name' => Fields::text($node, 'fname'),
            'last_name' => Fields::text($node, 'lname'),
            'phone' => Fields::phone($node),
            'timezone' => null,
            'date_of_birth' => null,
            'address' => Fields::crmAddress($node),
            'status' => CrmContract::status(false, false),
            'contact_type' => CrmContract::CUSTOMER,
            'record_type' => 'Client',
            'source' => Fields::SOURCE_LABEL,
            'created_at' => null,
            'company_gids' => $organization === null ? [] : [$organization],
            'list_gids' => [],
            'tag_gids' => [],
            'custom_fields' => [],
            'custom_group' => Fields::SOURCE_LABEL,
            'contact' => Fields::details($node, self::DETAILS),
        ]);
    }
}
