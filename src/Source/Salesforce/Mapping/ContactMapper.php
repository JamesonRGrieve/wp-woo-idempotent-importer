<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Salesforce\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A contact or an unconverted lead → a FluentCRM contact: email, salutation, first and last name, phone (else
 * mobile phone), birthdate, address (a contact's mailing address, a lead's address), created date, status from
 * email consent (opted out of email → unsubscribed, else transactional: Salesforce records no opt-in), a lead
 * contact type (Salesforce doesn't say who has bought; whether it was a Contact or a Lead is meta), its account
 * as its company, the campaigns it is a member of as
 * lists, its topics as tags, and the org's custom contact or lead fields as FluentCRM custom fields. Every other
 * field a user can set (title, department, lead source and status, owner, the lead's company name) is meta.
 * FluentCRM keeps one contact per email, so a record without an email address is not mirrored.
 */
final class ContactMapper implements Mapper
{
    private const VERSION = 1;
    private const CONTACT_NATIVE = [
        'Email', 'Salutation', 'FirstName', 'LastName', 'Phone', 'MobilePhone', 'Birthdate', 'MailingStreet', 'MailingCity',
        'MailingState', 'MailingPostalCode', 'MailingCountry', 'HasOptedOutOfEmail', 'AccountId', 'CreatedDate',
    ];
    private const LEAD_NATIVE = [
        'Email', 'Salutation', 'FirstName', 'LastName', 'Phone', 'MobilePhone', 'Street', 'City', 'State', 'PostalCode',
        'Country', 'HasOptedOutOfEmail', 'CreatedDate',
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
        $lead = $node->requiredChild('attributes')->string('type') === 'Lead';
        $email = Fields::text($node, 'Email') ?? throw new Unsupported('the record has no email address; FluentCRM keeps contacts by email.');
        $account = $lead ? null : Fields::text($node, 'AccountId');

        return new Desired(Kind::Contact, $node->id(), [
            'email' => strtolower($email),
            'prefix' => Fields::text($node, 'Salutation'),
            'first_name' => Fields::text($node, 'FirstName'),
            'last_name' => Fields::text($node, 'LastName'),
            'phone' => Fields::text($node, 'Phone') ?? Fields::text($node, 'MobilePhone'),
            'timezone' => null,
            'date_of_birth' => $lead ? null : Fields::text($node, 'Birthdate'),
            'address' => Fields::crmAddress($node, $lead ? '' : 'Mailing'),
            'status' => CrmContract::status(Fields::flag($node, 'HasOptedOutOfEmail'), false),
            'contact_type' => CrmContract::LEAD,
            'record_type' => $lead ? 'Lead' : 'Contact',
            'source' => Fields::SOURCE_LABEL,
            'created_at' => Fields::time($node, 'CreatedDate'),
            'company_gids' => $account === null ? [] : [Fields::id('Account', $account)],
            'list_gids' => array_map(static fn(string $id): string => Fields::id('Campaign', $id), $node->strings('campaign_ids')),
            'tag_gids' => array_map(static fn(string $id): string => Fields::id('Topic', $id), $node->strings('topic_ids')),
            'custom_fields' => Fields::customFields($node),
            'custom_group' => Fields::SOURCE_LABEL,
            'contact' => Fields::details($node, $lead ? self::LEAD_NATIVE : self::CONTACT_NATIVE),
        ]);
    }
}
