<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\LinkStore;
use IdempotentImporter\Wp\ContentHtml;
use IdempotentImporter\Wp\Log;
use IdempotentImporter\Wp\MediaLibrary;

/** What every writer shares: the identity map, media, rich-text preparation, settings and the log. */
final readonly class Targets
{
    public function __construct(
        public LinkStore $links,
        public MediaLibrary $media,
        public ContentHtml $html,
        public Settings $settings,
        public Log $log,
    ) {}

    /** The WordPress id of a mirrored dependency, or UnresolvedDependency. */
    public function require(Kind $kind, string $gid): int
    {
        return $this->links->targetId($kind, $gid)
            ?? throw new UnresolvedDependency("{$kind->value} {$gid} has not been mirrored yet.");
    }

    /**
     * The WordPress ids of mirrored dependencies; every one must exist.
     *
     * @param list<string> $gids
     *
     * @return list<int>
     */
    public function requireAll(Kind $kind, array $gids): array
    {
        return array_map(fn(string $gid): int => $this->require($kind, $gid), $gids);
    }
}
