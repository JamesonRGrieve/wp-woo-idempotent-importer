<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Model;

/**
 * Every kind of object the importer tracks in its link table, whatever the source. The values are
 * persisted; never rename one. Synced kinds are imported in SYNC_ORDER so dependencies exist first.
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
    /** A return order (an itemized return, or the return half of an exchange): refunds on the sales it returns. */
    case Return = 'return';
    /** A gift card or store credit account: mirrored into Square Gift Cards (SquareStoredValue), with its ledger. */
    case GiftCard = 'gift_card';
    /** A loyalty account (points): mirrored into Square Loyalty (SquareStoredValue), with its ledger. */
    case LoyaltyAccount = 'loyalty_account';
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
    /** A CRM company (account, organization): a FluentCRM company. */
    case CrmCompany = 'crm_company';
    /** A CRM list or segment: a FluentCRM list, its membership mirrored onto each contact. */
    case CrmList = 'crm_list';
    /** A CRM tag or label: a FluentCRM tag. */
    case CrmTag = 'crm_tag';
    /** A CRM contact (person, lead, client): a FluentCRM contact with its custom fields. */
    case Contact = 'crm_contact';
    /** A note or logged activity (call, email, meeting, task) on a contact: a FluentCRM contact note. */
    case CrmNote = 'crm_note';
    /** A CMS collection (a content type): a custom post type the plugin registers. */
    case ContentType = 'content_type';
    /** An item of a CMS collection: a post of its collection's post type. */
    case ContentItem = 'content_item';

    /** The kinds a run can read, in dependency order (each adapter provides a subset). */
    public const SYNC_ORDER = [
        self::Shop,
        self::File,
        self::Collection,
        self::Product,
        self::Customer,
        self::CrmCompany,
        self::CrmList,
        self::CrmTag,
        self::Contact,
        self::CrmNote,
        self::Discount,
        self::Order,
        self::Return,
        self::GiftCard,
        self::LoyaltyAccount,
        self::DraftOrder,
        self::Page,
        self::Blog,
        self::Article,
        self::ContentType,
        self::ContentItem,
        self::Comment,
        self::Menu,
        self::UrlRedirect,
        self::DeliveryProfile,
        self::Review,
    ];
}
