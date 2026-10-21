<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use IdempotentImporter\Source\SourceError;

/**
 * The shared field contract of CRM data (FluentCRM's vocabulary), so every CRM and accounting mapper speaks it
 * the same way: contact statuses from the source's consent, activity types, custom field definitions, and the
 * address shape. ContactWriter, CrmCompanyWriter and CrmNoteWriter read what these build.
 */
final class CrmContract
{
    /** FluentCRM contact statuses the importer writes. */
    public const SUBSCRIBED = 'subscribed';
    public const UNSUBSCRIBED = 'unsubscribed';
    /** A contact who gave no marketing consent: FluentCRM sends them transactional email only. */
    public const TRANSACTIONAL = 'transactional';
    public const BOUNCED = 'bounced';
    /** FluentCRM contact types. */
    public const LEAD = 'lead';
    public const CUSTOMER = 'customer';
    /** FluentCRM activity (note) types the importer writes. */
    public const NOTE = 'note';
    public const CALL = 'call';
    public const EMAIL = 'email';
    public const MEETING = 'meeting';
    public const INVOICE_SENT = 'invoice_sent';
    public const INVOICE_PAID = 'invoice_paid';
    /** FluentCRM custom field types. */
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const NUMBER = 'number';
    public const SELECT = 'select-one';
    public const MULTI_SELECT = 'select-multi';
    public const CHECKBOX = 'checkbox';
    public const DATE = 'date';
    public const DATE_TIME = 'date_time';
    private const DATE_TIME_FORMAT = 'Y-m-d H:i:s';
    /** FluentCRM keeps a slug of at most this many characters for a custom field. */
    private const MAX_SLUG_LENGTH = 60;
    private const ADDRESS_FIELDS = ['address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country'];

    /**
     * A contact's status from what the source says about email consent: opted out (or unsubscribed) is
     * unsubscribed, a recorded opt-in is subscribed, and no consent either way is transactional (FluentCRM sends
     * such a contact no marketing).
     */
    public static function status(bool $optedOut, bool $optedIn): string
    {
        return $optedOut ? self::UNSUBSCRIBED : ($optedIn ? self::SUBSCRIBED : self::TRANSACTIONAL);
    }

    /**
     * One custom field: its FluentCRM slug (`<source>_<field key>`), label, type, choices and value.
     *
     * @param list<string> $options
     * @param string|list<string>|null $value
     *
     * @return array{key: string, label: string, type: string, options: list<string>, value: string|list<string>|null}
     */
    public static function customField(string $source, string $key, string $label, string $type, array $options, string|array|null $value): array
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', "{$source}_{$key}"));

        return ['key' => substr(trim($slug, '_'), 0, self::MAX_SLUG_LENGTH), 'label' => $label, 'type' => $type, 'options' => $options, 'value' => $value];
    }

    /**
     * An address in FluentCRM's shape, null when it has nothing in it.
     *
     * @param array<string, ?string> $parts keyed by address_line_1, address_line_2, city, state, postal_code, country
     *
     * @return array<string, string>|null
     */
    public static function address(array $parts): ?array
    {
        $address = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $value = trim($parts[$field] ?? '');
            if ($value !== '') {
                $address[$field] = $value;
            }
        }

        return $address === [] ? null : $address;
    }

    /** A timestamp as FluentCRM's date-and-time custom field value (`Y-m-d H:i:s`, UTC). */
    public static function dateTime(string $timestamp): string
    {
        try {
            $utc = new DateTimeZone('UTC');

            return (new DateTimeImmutable($timestamp, $utc))->setTimezone($utc)->format(self::DATE_TIME_FORMAT);
        } catch (Exception) {
            throw new SourceError("Not a timestamp: {$timestamp}");
        }
    }

    /** Plain text as the HTML of a note's description (paragraphs and line breaks kept). */
    public static function textHtml(?string $text): string
    {
        $text = trim($text ?? '');
        if ($text === '') {
            return '';
        }

        return implode('', array_map(
            static fn(string $paragraph): string => '<p>' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES), false) . '</p>',
            preg_split('/\R{2,}/', $text) ?: [$text],
        ));
    }

    /**
     * Details of an activity with no field of their own in a FluentCRM note (its status, direction, duration,
     * outcome, owner…), as a definition list appended to the note.
     *
     * @param array<string, ?string> $details label => value
     */
    public static function detailsHtml(array $details): string
    {
        $rows = '';
        foreach ($details as $label => $value) {
            if ($value !== null && $value !== '') {
                $rows .= '<dt>' . htmlspecialchars($label, ENT_QUOTES) . '</dt><dd>' . htmlspecialchars($value, ENT_QUOTES) . '</dd>';
            }
        }

        return $rows === '' ? '' : "<dl>{$rows}</dl>";
    }
}
