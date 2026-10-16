<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Meta;

/** Meta storage of one WordPress object, whatever its type (post, term, user, WooCommerce object). */
interface MetaTarget
{
    public function get(string $key): mixed;

    /** @param string|array<array-key, mixed> $value */
    public function set(string $key, string|array $value): void;

    public function delete(string $key): void;
}
