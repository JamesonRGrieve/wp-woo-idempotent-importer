<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\HubSpot\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Times;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A note, call, email or meeting → a FluentCRM note of that type (note, call, email, meeting) on each contact
 * and company it is associated with (the reader gives each its own identity): its title (the call's or
 * meeting's title, the email's subject, else the type), its body as the description (an email's HTML, else its
 * text) with the details FluentCRM has no field for (direction, status, duration, outcome, location, times,
 * recording) listed after it, and its timestamp. Its owner and attachments are meta.
 */
final class EngagementMapper implements Mapper
{
    private const VERSION = 1;
    private const MILLISECONDS_PER_SECOND = 1000;
    /** HubSpot object type → [FluentCRM type, title property, body properties, details by label]. */
    private const TYPES = [
        'notes' => [CrmContract::NOTE, null, ['hs_note_body'], []],
        'calls' => [CrmContract::CALL, 'hs_call_title', ['hs_call_body'], [
            'Direction' => 'hs_call_direction', 'Status' => 'hs_call_status', 'From' => 'hs_call_from_number', 'To' => 'hs_call_to_number',
            'Recording' => 'hs_call_recording_url',
        ]],
        'emails' => [CrmContract::EMAIL, 'hs_email_subject', ['hs_email_html', 'hs_email_text'], ['Direction' => 'hs_email_direction', 'Status' => 'hs_email_status']],
        'meetings' => [CrmContract::MEETING, 'hs_meeting_title', ['hs_meeting_body'], [
            'Starts' => 'hs_meeting_start_time', 'Ends' => 'hs_meeting_end_time', 'Location' => 'hs_meeting_location', 'Outcome' => 'hs_meeting_outcome',
            'Internal notes' => 'hs_internal_meeting_notes', 'Link' => 'hs_meeting_external_url',
        ]],
    ];
    private const TYPE_TITLES = [CrmContract::NOTE => 'Note', CrmContract::CALL => 'Call', CrmContract::EMAIL => 'Email', CrmContract::MEETING => 'Meeting'];

    public function kind(): Kind
    {
        return Kind::CrmNote;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        [$type, $titleProperty, $bodyProperties, $detailProperties] = self::TYPES[$node->string('engagement_type')];
        $body = null;
        foreach ($bodyProperties as $property) {
            $body ??= Fields::property($node, $property);
        }
        $bodyHtml = $body === null ? '' : (str_contains($body, '<') ? $body : CrmContract::textHtml($body));
        $details = [];
        foreach ($detailProperties as $label => $property) {
            $details[$label] = Fields::property($node, $property);
        }
        if ($type === CrmContract::CALL) {
            $duration = Fields::property($node, 'hs_call_duration');
            $details['Duration'] = $duration === null ? null : gmdate('H:i:s', intdiv((int) $duration, self::MILLISECONDS_PER_SECOND));
        }
        $contact = $node->optionalString('contact_id');
        $company = $node->optionalString('company_id');

        return new Desired(Kind::CrmNote, $node->id(), [
            'contact_gid' => $contact === null ? null : Fields::id('Contact', $contact),
            'company_gid' => $company === null ? null : Fields::id('Company', $company),
            'type' => $type,
            'title' => ($titleProperty === null ? null : Fields::property($node, $titleProperty)) ?? self::TYPE_TITLES[$type],
            'description_html' => $bodyHtml . CrmContract::detailsHtml($details),
            'created_at' => Fields::time($node, 'hs_timestamp') ?? Times::requiredIso($node, 'createdAt'),
            'owner_id' => Fields::property($node, 'hubspot_owner_id'),
            'attachment_ids' => Fields::property($node, 'hs_attachment_ids'),
        ]);
    }
}
