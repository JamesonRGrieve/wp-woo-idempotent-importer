<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\PlaceholderEmail;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Pipedrive\PipedriveAdapter;

/**
 * A person → a FluentCRM contact: the primary email, first and last name, the primary phone, birthday, postal
 * address, created date, status from the person's marketing status (`subscribed` → subscribed, `unsubscribed`
 * or `archived` → unsubscribed, `no_consent` or none → transactional), its organization as its company, its
 * labels as tags, the person filters it matches as lists, and the account's own person fields as FluentCRM
 * custom fields. Its other emails and phones, job title, contact sync notes, instant messaging accounts, owner
 * and visibility are meta. FluentCRM keeps one contact per email, so a person without one gets a
 * placeholder under `.invalid` (PlaceholderEmail), which no mail is ever sent to.
 */
final class PersonMapper implements Mapper
{
    private const VERSION = 2;
    private const SUBSCRIBED = 'subscribed';
    private const OPTED_OUT = ['unsubscribed', 'archived'];
    private const DETAILS = ['emails', 'phones', 'job_title', 'notes', 'im', 'owner_id', 'visible_to', 'marketing_status'];

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
        $email = Fields::primary($node, 'emails');
        $marketing = $node->optionalString('marketing_status');
        $org = $node->optionalInt('org_id');

        return new Desired(Kind::Contact, $node->id(), [
            ...PlaceholderEmail::fields($email, PipedriveAdapter::ID, 'person', Fields::sourceId($node)),
            'prefix' => null,
            'first_name' => $node->optionalString('first_name'),
            'last_name' => $node->optionalString('last_name'),
            'phone' => Fields::primary($node, 'phones'),
            'timezone' => null,
            'date_of_birth' => $node->optionalString('birthday'),
            'address' => Fields::crmAddress($node->child('postal_address')),
            'status' => CrmContract::status(in_array($marketing, self::OPTED_OUT, true), $marketing === self::SUBSCRIBED),
            'contact_type' => CrmContract::LEAD,
            'source' => Fields::SOURCE_LABEL,
            'created_at' => Fields::time($node, 'add_time'),
            'company_gids' => $org === null ? [] : [Fields::id('Organization', $org)],
            'list_gids' => array_map(static fn(int $id): string => Fields::id('Filter', $id), $node->ints('filter_ids')),
            'tag_gids' => array_map(static fn(int $id): string => Fields::id('Label', $id), $node->ints('label_ids')),
            'custom_fields' => Fields::customFields($node),
            'custom_group' => Fields::SOURCE_LABEL,
            'person' => array_filter($node->only(self::DETAILS), static fn(mixed $value): bool => $value !== null && $value !== []),
        ]);
    }
}
