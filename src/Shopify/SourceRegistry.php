<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Shopify;

use IdempotentShopifyImporter\Model\Kind;

/** The Shopify read definition of every synced kind except Shop (a singleton with its own operation). */
final class SourceRegistry
{
    private const METAFIELDS = 'MetafieldConnectionFields';

    /** @return array<string, Source> keyed by Kind value, in Kind::SYNC_ORDER */
    public static function all(): array
    {
        $updatedAt = Source::INCREMENTAL_SORT_KEY;
        $metafields = new NestedConnection('metafields', self::METAFIELDS);
        $list = [
            new Source(Kind::File, 'File', 'files', 'FileFields', $updatedAt, true),
            new Source(Kind::Collection, 'Collection', 'collections', 'CollectionFields', $updatedAt, true, [$metafields]),
            new Source(Kind::Product, 'Product', 'products', 'ProductFields', $updatedAt, true, [
                new NestedConnection('variants', 'ProductVariantConnectionFields'),
                new NestedConnection('media', 'MediaConnectionFields'),
                new NestedConnection('collections', 'CollectionIdConnectionFields'),
                $metafields,
            ]),
            new Source(Kind::Customer, 'Customer', 'customers', 'CustomerFields', $updatedAt, true, [
                new NestedConnection('addressesV2', 'MailingAddressConnectionFields'),
                $metafields,
            ]),
            new Source(Kind::Discount, 'DiscountNode', 'discountNodes', 'DiscountNodeFields', $updatedAt, true, [], 'discountNode'),
            new Source(Kind::Order, 'Order', 'orders', 'OrderFields', $updatedAt, true, [
                new NestedConnection('lineItems', 'LineItemConnectionFields'),
                new NestedConnection('shippingLines', 'ShippingLineConnectionFields', 'includeRemovals: false'),
                new NestedConnection('events', 'EventConnectionFields'),
                $metafields,
            ]),
            new Source(Kind::DraftOrder, 'DraftOrder', 'draftOrders', 'DraftOrderFields', $updatedAt, true, [
                new NestedConnection('lineItems', 'DraftOrderLineItemConnectionFields'),
                $metafields,
            ]),
            new Source(Kind::Page, 'Page', 'pages', 'PageFields', $updatedAt, true, [$metafields]),
            new Source(Kind::Blog, 'Blog', 'blogs', 'BlogFields', null, true, [$metafields]),
            new Source(Kind::Article, 'Article', 'articles', 'ArticleFields', $updatedAt, true, [$metafields]),
            new Source(Kind::Comment, 'Comment', 'comments', 'CommentFields', null, true),
            new Source(Kind::Menu, 'Menu', 'menus', 'MenuFields', null, true),
            new Source(Kind::UrlRedirect, 'UrlRedirect', 'urlRedirects', 'UrlRedirectFields', null, true),
            new Source(Kind::DeliveryProfile, 'DeliveryProfile', 'deliveryProfiles', 'DeliveryProfileFields', null, false),
        ];
        $sources = [];
        foreach ($list as $source) {
            $sources[$source->kind->value] = $source;
        }

        return $sources;
    }

    public static function for(Kind $kind): Source
    {
        return self::all()[$kind->value] ?? throw new ShopifyError("{$kind->value} has no Shopify list source");
    }
}
