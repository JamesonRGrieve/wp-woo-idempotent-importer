<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp\Writer;

use IdempotentShopifyImporter\Mapping\Desired;
use IdempotentShopifyImporter\Mapping\Values;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Shopify\ShopifyError;
use IdempotentShopifyImporter\Store\Link;
use IdempotentShopifyImporter\Wp\Meta\MetaSync;
use IdempotentShopifyImporter\Wp\Meta\WpMeta;
use IdempotentShopifyImporter\Wp\Retirement;
use WP_Post;
use WP_Term;

/**
 * Navigation menus → WordPress menus. Items pointing at mirrored products, collections, pages, blogs
 * and articles link to their WordPress objects; everything else becomes a custom link. A changed menu's
 * items are rebuilt; the menu itself (and its theme location) is kept.
 */
final class MenuWriter implements Writer
{
    private const MENU_TAXONOMY = 'nav_menu';
    private const RESOURCE_KINDS = [
        'PRODUCT' => [Kind::Product, 'post_type', 'product'],
        'COLLECTION' => [Kind::Collection, 'taxonomy', 'product_cat'],
        'PAGE' => [Kind::Page, 'post_type', 'page'],
        'BLOG' => [Kind::Blog, 'taxonomy', 'category'],
        'ARTICLE' => [Kind::Article, 'post_type', 'post'],
    ];

    public function __construct(private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return Kind::Menu;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $menuId = $existing !== null && $this->exists($existing->targetId) ? $existing->targetId : 0;
        $menuId = wp_update_nav_menu_object($menuId, ['menu-name' => $f->string('title')]);
        if (is_wp_error($menuId)) {
            throw new ShopifyError("Could not save menu \"{$f->string('title')}\": " . $menuId->get_error_message());
        }
        foreach (wp_get_nav_menu_items($menuId, ['post_status' => 'any']) ?: [] as $item) {
            if ($item instanceof WP_Post) {
                wp_delete_post($item->ID, true);
            }
        }
        $this->items($menuId, $f->records('items'), 0);
        MetaSync::fields(WpMeta::term($menuId), [MetaSync::GID => $desired->gid, MetaSync::DELETED => null, '_shopify_handle' => $f->string('handle')]);

        return $menuId;
    }

    public function exists(int $targetId): bool
    {
        return get_term($targetId, self::MENU_TAXONOMY) instanceof WP_Term;
    }

    public function retire(Link $link): void
    {
        Retirement::mark(WpMeta::term($link->targetId));
    }

    /** @param list<Values> $items */
    private function items(int $menuId, array $items, int $parentItemId): void
    {
        foreach ($items as $position => $item) {
            $itemId = wp_update_nav_menu_item($menuId, 0, [
                'menu-item-title' => $item->string('title'),
                'menu-item-parent-id' => $parentItemId,
                'menu-item-position' => $position + 1,
                'menu-item-status' => 'publish',
                ...$this->target($item),
            ]);
            if (is_wp_error($itemId)) {
                throw new ShopifyError("Could not save menu item \"{$item->string('title')}\": " . $itemId->get_error_message());
            }
            $this->items($menuId, $item->records('children'), $itemId);
        }
    }

    /** @return array<string, int|string> */
    private function target(Values $item): array
    {
        [$kind, $type, $object] = self::RESOURCE_KINDS[$item->string('type')] ?? [null, null, null];
        $gid = $item->nullableString('resource_gid');
        $objectId = $kind === null || $gid === null ? null : $this->targets->links->targetId($kind, $gid);
        if ($objectId !== null && $type !== null && $object !== null) {
            return ['menu-item-type' => $type, 'menu-item-object' => $object, 'menu-item-object-id' => $objectId];
        }

        return ['menu-item-type' => 'custom', 'menu-item-url' => $item->nullableString('url') ?? '#'];
    }
}
