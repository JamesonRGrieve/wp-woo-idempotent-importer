<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Zoho\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\PlaceholderEmail;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Zoho\ZohoAdapter;

/**
 * A contact or an unconverted lead → a FluentCRM contact: email, salutation, first and last name, phone (else
 * mobile), birthdate (contacts), mailing address (contacts) or address (leads), created time, status from email
 * consent (Email Opt Out → unsubscribed, else transactional: Zoho records no opt-in on the record), lead contact
 * type, its account as its company (contacts), its tags, the campaigns it is a member of as lists, and the org's
 * custom fields as FluentCRM custom fields. Every other field a user can set (title, department, lead source and
 * status, a lead's company name, owner) is meta. FluentCRM keeps one contact per email, so a record without an
 * email address gets a placeholder under `.invalid` (PlaceholderEmail), which no mail is ever sent to.
 */
final class ContactMapper implements Mapper
{
    private const VERSION = 2;
    private const CONTACT_NATIVE = [
        'Email', 'Salutation', 'First_Name', 'Last_Name', 'Phone', 'Mobile', 'Date_of_Birth', 'Mailing_Street', 'Mailing_City',
        'Mailing_State', 'Mailing_Zip', 'Mailing_Country', 'Email_Opt_Out', 'Account_Name', 'Tag', 'Created_Time',
    ];
    private const LEAD_NATIVE = [
        'Email', 'Salutation', 'First_Name', 'Last_Name', 'Phone', 'Mobile', 'Street', 'City', 'State', 'Zip_Code', 'Country',
        'Email_Opt_Out', 'Tag', 'Created_Time',
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
        $lead = $node->string('module') === 'Leads';
        $email = Fields::text($node, 'Email');
        $account = $lead ? null : Fields::lookupId($node, 'Account_Name');
        $tags = array_map(static fn(Node $tag): string => Fields::id('Tag', $tag->string('id')), is_array($node->raw()['Tag'] ?? null) ? $node->list('Tag') : []);

        return new Desired(Kind::Contact, $node->id(), [
            ...PlaceholderEmail::fields($email, ZohoAdapter::ID, 'record', Fields::sourceId($node)),
            'prefix' => Fields::text($node, 'Salutation'),
            'first_name' => Fields::text($node, 'First_Name'),
            'last_name' => Fields::text($node, 'Last_Name'),
            'phone' => Fields::text($node, 'Phone') ?? Fields::text($node, 'Mobile'),
            'timezone' => null,
            'date_of_birth' => $lead ? null : Fields::text($node, 'Date_of_Birth'),
            'address' => $lead ? Fields::crmAddress($node, '', 'Zip_Code', 'Country') : Fields::crmAddress($node, 'Mailing_', 'Mailing_Zip', 'Mailing_Country'),
            'status' => CrmContract::status(Fields::flag($node, 'Email_Opt_Out'), false),
            'contact_type' => CrmContract::LEAD,
            'record_type' => $lead ? 'Lead' : 'Contact',
            'source' => Fields::SOURCE_LABEL,
            'created_at' => Fields::time($node, 'Created_Time'),
            'company_gids' => $account === null ? [] : [Fields::id('Account', $account)],
            'list_gids' => array_map(static fn(string $id): string => Fields::id('Campaign', $id), $node->strings('campaign_ids')),
            'tag_gids' => $tags,
            'custom_fields' => Fields::customFields($node),
            'custom_group' => Fields::SOURCE_LABEL,
            'contact' => Fields::details($node, $lead ? self::LEAD_NATIVE : self::CONTACT_NATIVE),
        ]);
    }
}
