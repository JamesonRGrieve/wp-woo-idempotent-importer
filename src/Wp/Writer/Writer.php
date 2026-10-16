<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;

/** Writes one kind's desired state into WordPress, touching only the fields the mapping owns. */
interface Writer
{
    public function kind(): Kind;

    /**
     * Creates or updates the WordPress object and returns its id.
     *
     * @throws UnresolvedDependency when something it needs hasn't been mirrored yet; retried next run
     */
    public function write(Desired $desired, ?Link $existing): int;

    /** Whether the linked WordPress object still exists (someone may have deleted it by hand). */
    public function exists(int $targetId): bool;

    /** The Shopify object is gone: trash the copy where WordPress has a trash, otherwise mark it. */
    public function retire(Link $link): void;
}
