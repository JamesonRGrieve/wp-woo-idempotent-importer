<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Tag;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\Fluent\FluentCrm;
use IdempotentImporter\Wp\Fluent\FluentMeta;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Retirement;

/**
 * A kind mirrored as a FluentCRM grouping of contacts, which has a title, a slug and a description: CRM lists
 * and segments → FluentCRM lists, CRM tags and labels → FluentCRM tags. Membership is written on each contact
 * (ContactWriter). The slug is the title's, made unique among FluentCRM's lists or tags. FluentCRM has no trash:
 * a retired list or tag stays (its contacts keep it) and is marked.
 */
final class CrmGroupWriter implements Writer
{
    private const CONSUMED = ['title', 'description'];

    /** @param class-string<Lists|Tag> $modelClass */
    public function __construct(private readonly Kind $kind, private readonly string $modelClass) {}

    public function kind(): Kind
    {
        return $this->kind;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        FluentCrm::require();
        $f = Values::of($desired);
        $group = $existing === null ? null : $this->find($existing->targetId);
        $group ??= new ($this->modelClass)();
        $title = $f->string('title');
        $slug = FluentCrm::uniqueSlug($this->modelClass, $title, substr(hash('sha256', $desired->gid), 0, 8), FluentCrm::id($group));
        FluentCrm::fill($group, ['title' => $title, 'slug' => $slug, 'description' => $f->nullableString('description')]);
        $id = FluentCrm::id($group);
        MetaSync::mirror(new FluentMeta($this->kind, $id), $desired->gid, $f, self::CONSUMED);

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return defined('FLUENTCRM') && $this->find($targetId) !== null;
    }

    public function retire(Link $link): void
    {
        Retirement::mark(new FluentMeta($this->kind, $link->targetId));
    }

    private function find(int $id): Lists|Tag|null
    {
        return $this->modelClass === Lists::class ? FluentCrm::list($id) : FluentCrm::tag($id);
    }
}
