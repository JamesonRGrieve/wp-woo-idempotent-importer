<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Meta;

use WC_Data;

/** Meta of a WooCommerce CRUD object (product, order, coupon); persisted by the object's save(). */
final readonly class WcDataMeta implements MetaTarget
{
    public function __construct(private WC_Data $object) {}

    public function get(string $key): mixed
    {
        return $this->object->get_meta($key);
    }

    public function set(string $key, string|array $value): void
    {
        $this->object->update_meta_data($key, $value);
    }

    public function delete(string $key): void
    {
        $this->object->delete_meta_data($key);
    }
}
