<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp\Writer;

use IdempotentShopifyImporter\Config\Settings;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Store\LinkStore;
use IdempotentShopifyImporter\Wp\ContentHtml;
use IdempotentShopifyImporter\Wp\Log;
use IdempotentShopifyImporter\Wp\MediaLibrary;

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
