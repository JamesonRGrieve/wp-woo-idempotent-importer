<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Meta\WpMeta;
use IdempotentImporter\Wp\Retirement;

/** Shopify Files → media library attachments (shared with every other use of the same URL). */
final class FileWriter implements Writer
{
    public function __construct(private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return Kind::File;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $id = $this->targets->media->attachment($f->string('download_url'), $f->nullableString('alt'));
        MetaSync::fields(WpMeta::post($id), [MetaSync::GID => $desired->gid, MetaSync::DELETED => null]);

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return get_post($targetId) !== null;
    }

    public function retire(Link $link): void
    {
        Retirement::mark(WpMeta::post($link->targetId));
    }
}
