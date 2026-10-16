<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Model;

/**
 * Every kind of Shopify object the importer tracks in its link table. The values are persisted; never
 * rename one. The synced kinds are imported in the order of SYNC_ORDER so dependencies exist first.
 */
enum Kind: string
{
    case Shop = 'shop';
    case Policy = 'policy';
    case File = 'file';
    case Media = 'media';
    case Collection = 'collection';
    case Product = 'product';
    case Variant = 'variant';
    case Customer = 'customer';
    case Discount = 'discount';
    case DiscountCode = 'discount_code';
    case Order = 'order';
    case Refund = 'refund';
    case DraftOrder = 'draft_order';
    case Page = 'page';
    case Blog = 'blog';
    case Article = 'article';
    case Comment = 'comment';
    case Menu = 'menu';
    case UrlRedirect = 'url_redirect';
    case DeliveryProfile = 'delivery_profile';
    case ShippingZone = 'shipping_zone';
    case Review = 'review';

    /** The kinds a run reads from Shopify, in dependency order. */
    public const SYNC_ORDER = [
        self::Shop,
        self::File,
        self::Collection,
        self::Product,
        self::Customer,
        self::Discount,
        self::Order,
        self::DraftOrder,
        self::Page,
        self::Blog,
        self::Article,
        self::Comment,
        self::Menu,
        self::UrlRedirect,
        self::DeliveryProfile,
    ];
}
