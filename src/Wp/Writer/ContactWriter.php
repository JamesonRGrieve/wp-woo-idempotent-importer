<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use FluentCrm\App\Models\Subscriber;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\Fluent\CustomFields;
use IdempotentImporter\Wp\Fluent\FluentCrm;
use IdempotentImporter\Wp\Fluent\FluentMeta;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Retirement;

/**
 * CRM contacts (and accounting clients) → FluentCRM contacts: name, email, phone, address, timezone, birthday,
 * status, contact type and source; their companies (the first is the primary company), lists and tags among
 * those this source mirrors; and the source's custom fields as FluentCRM custom fields. A FluentCRM contact
 * with the same email is adopted rather than duplicated (FluentCRM keeps one contact per email; the last record
 * written owns it). Written
 * through FluentCRM's models, so no FluentCRM event, automation or email is started. FluentCRM has no trash: a
 * retired contact is marked.
 */
final class ContactWriter implements Writer
{
    private const ATTRIBUTES = ['email', 'prefix', 'first_name', 'last_name', 'phone', 'timezone', 'date_of_birth', 'status', 'contact_type', 'source'];
    private const ADDRESS = ['address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country'];
    private const CONSUMED = [
        ...self::ATTRIBUTES, 'address', 'created_at', 'company_gids', 'list_gids', 'tag_gids', 'custom_fields', 'custom_group',
    ];

    public function __construct(private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return Kind::Contact;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        FluentCrm::require();
        $f = Values::of($desired);
        $email = strtolower($f->string('email'));
        $contact = ($existing === null ? null : FluentCrm::contact($existing->targetId)) ?? FluentCrm::contactByEmail($email) ?? new Subscriber();
        $attributes = [];
        foreach (self::ATTRIBUTES as $name) {
            $attributes[$name] = $f->nullableString($name);
        }
        $attributes['email'] = $email;
        $address = $f->record('address');
        foreach (self::ADDRESS as $name) {
            $attributes[$name] = $address?->nullableString($name);
        }
        $createdAt = $f->nullableString('created_at');
        if (FluentCrm::id($contact) === 0 && $createdAt !== null) {
            $attributes['created_at'] = FluentCrm::localTime($createdAt);
        }
        FluentCrm::fill($contact, $attributes);
        $id = FluentCrm::id($contact);
        $source = Identity::prefixOf($desired->gid);
        $companies = $this->memberships($contact, Kind::CrmCompany, FluentCrm::COMPANY_TYPE, $f->strings('company_gids'), $source);
        if ($companies !== null) {
            FluentCrm::fill($contact, ['company_id' => $companies[0] ?? null]);
        }
        $this->memberships($contact, Kind::CrmList, FluentCrm::LIST_TYPE, $f->strings('list_gids'), $source);
        $this->memberships($contact, Kind::CrmTag, FluentCrm::TAG_TYPE, $f->strings('tag_gids'), $source);
        CustomFields::writeContactValues($contact, CustomFields::define(false, $f->string('custom_group'), $f->records('custom_fields')));
        MetaSync::mirror(new FluentMeta(Kind::Contact, $id), $desired->gid, $f, self::CONSUMED);

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return defined('FLUENTCRM') && FluentCrm::contact($targetId) !== null;
    }

    /**
     * Marks the contact, unless another mirrored record has since adopted it (a converted lead's contact, the same
     * person in another source): the contact is that record's now, and alive.
     */
    public function retire(Link $link): void
    {
        $meta = new FluentMeta(Kind::Contact, $link->targetId);
        $owner = $meta->get(MetaSync::GID);
        if ($owner === null || $owner === '' || $owner === $link->gid) {
            Retirement::mark($meta);
        }
    }

    /**
     * The contact's memberships of one kind made the source's (when that kind is mirrored); returns the ids in
     * the source's order, or null when the kind isn't mirrored (then memberships are left alone).
     *
     * @param class-string<\FluentCrm\App\Models\Lists|\FluentCrm\App\Models\Tag|\FluentCrm\App\Models\Company> $objectType
     * @param list<string> $gids
     *
     * @return list<int>|null
     */
    private function memberships(Subscriber $contact, Kind $kind, string $objectType, array $gids, string $source): ?array
    {
        if (!$this->targets->settings->syncs($kind)) {
            return null;
        }
        $ids = $this->targets->requireAll($kind, $gids);
        $managed = array_map(static fn(Link $link): int => $link->targetId, $this->targets->links->withPrefix($kind, $source));
        FluentCrm::syncMemberships($contact, $objectType, $ids, $managed);

        return $ids;
    }
}
