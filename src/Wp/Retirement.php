<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp;

use IdempotentShopifyImporter\Wp\Meta\MetaSync;
use IdempotentShopifyImporter\Wp\Meta\MetaTarget;
use IdempotentShopifyImporter\Wp\Meta\WpMeta;
use WC_Data;

/**
 * Retiring a copy whose Shopify object is gone: marked with the time it was retired, then trashed where
 * WordPress has a trash. Nothing is ever deleted permanently.
 */
final class Retirement
{
    public static function mark(MetaTarget $target): void
    {
        $target->set(MetaSync::DELETED, gmdate('c'));
    }

    public static function wcObject(?WC_Data $object): void
    {
        if ($object === null || $object->get_id() === 0) {
            return;
        }
        $object->update_meta_data(MetaSync::DELETED, gmdate('c'));
        $object->save();
        $object->delete(false);
    }

    public static function post(int $postId): void
    {
        if (get_post($postId) !== null) {
            self::mark(WpMeta::post($postId));
            wp_trash_post($postId);
        }
    }

    public static function comment(int $commentId): void
    {
        if (get_comment($commentId) !== null) {
            self::mark(WpMeta::comment($commentId));
            wp_trash_comment($commentId);
        }
    }
}
