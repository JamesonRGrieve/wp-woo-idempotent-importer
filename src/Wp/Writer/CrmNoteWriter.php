<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use FluentCrm\App\Models\CompanyNote;
use FluentCrm\App\Models\SubscriberNote;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\Fluent\FluentCrm;
use IdempotentImporter\Wp\Fluent\FluentMeta;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Retirement;

/**
 * CRM notes and logged activities (calls, emails, meetings, tasks) → FluentCRM notes on the contact's profile
 * (`contact_gid`) or the company's (`company_gid`): the FluentCRM activity type, title, HTML description and
 * date. A note or activity a source ties to several contacts is one mirrored note per contact (the reader
 * gives each its own identity). FluentCRM has no trash: a retired note is marked.
 */
final class CrmNoteWriter implements Writer
{
    private const CONSUMED = ['contact_gid', 'company_gid', 'type', 'title', 'description_html', 'created_at'];

    public function __construct(private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return Kind::CrmNote;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        FluentCrm::require();
        $f = Values::of($desired);
        $companyGid = $f->nullableString('company_gid');
        $ofCompany = $companyGid !== null;
        $ownerId = $ofCompany ? $this->targets->require(Kind::CrmCompany, $companyGid) : $this->targets->require(Kind::Contact, $f->string('contact_gid'));
        $note = ($existing === null ? null : FluentCrm::note($existing->targetId, $ofCompany)) ?? ($ofCompany ? new CompanyNote() : new SubscriberNote());
        FluentCrm::fill($note, [
            'subscriber_id' => $ownerId,
            'type' => $f->string('type'),
            'title' => $f->string('title'),
            'description' => $f->string('description_html'),
            'created_at' => FluentCrm::localTime($f->string('created_at')),
        ]);
        $id = FluentCrm::id($note);
        MetaSync::mirror(new FluentMeta(Kind::CrmNote, $id), $desired->gid, $f, self::CONSUMED);

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return defined('FLUENTCRM') && (FluentCrm::note($targetId, false) ?? FluentCrm::note($targetId, true)) !== null;
    }

    public function retire(Link $link): void
    {
        Retirement::mark(new FluentMeta(Kind::CrmNote, $link->targetId));
    }
}
