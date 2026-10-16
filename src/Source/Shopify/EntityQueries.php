<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\SourceError;

/** The Shopify read definition of every synced kind except Shop (a singleton with its own operation). */
final class EntityQueries
{
    private const METAFIELDS = 'MetafieldConnectionFields';

    /** @return array<string, EntityQuery> keyed by Kind value, in Kind::SYNC_ORDER */
    public static function all(): array
    {
        $updatedAt = EntityQuery::INCREMENTAL_SORT_KEY;
        $metafields = new NestedConnection('metafields', self::METAFIELDS);
        $list = [
            new EntityQuery(Kind::File, 'File', 'files', 'FileFields', $updatedAt, true),
            new EntityQuery(Kind::Collection, 'Collection', 'collections', 'CollectionFields', $updatedAt, true, [$metafields]),
            new EntityQuery(Kind::Product, 'Product', 'products', 'ProductFields', $updatedAt, true, [
                new NestedConnection('variants', 'ProductVariantConnectionFields'),
                new NestedConnection('media', 'MediaConnectionFields'),
                new NestedConnection('collections', 'CollectionIdConnectionFields'),
                $metafields,
            ]),
            new EntityQuery(Kind::Customer, 'Customer', 'customers', 'CustomerFields', $updatedAt, true, [
                new NestedConnection('addressesV2', 'MailingAddressConnectionFields'),
                $metafields,
            ]),
            new EntityQuery(Kind::Discount, 'DiscountNode', 'discountNodes', 'DiscountNodeFields', $updatedAt, true, [], 'discountNode'),
            new EntityQuery(Kind::Order, 'Order', 'orders', 'OrderFields', $updatedAt, true, [
                new NestedConnection('lineItems', 'LineItemConnectionFields'),
                new NestedConnection('shippingLines', 'ShippingLineConnectionFields', 'includeRemovals: false'),
                new NestedConnection('events', 'EventConnectionFields'),
                $metafields,
            ]),
            new EntityQuery(Kind::DraftOrder, 'DraftOrder', 'draftOrders', 'DraftOrderFields', $updatedAt, true, [
                new NestedConnection('lineItems', 'DraftOrderLineItemConnectionFields'),
                $metafields,
            ]),
            new EntityQuery(Kind::Page, 'Page', 'pages', 'PageFields', $updatedAt, true, [$metafields]),
            new EntityQuery(Kind::Blog, 'Blog', 'blogs', 'BlogFields', null, true, [$metafields]),
            new EntityQuery(Kind::Article, 'Article', 'articles', 'ArticleFields', $updatedAt, true, [$metafields]),
            new EntityQuery(Kind::Comment, 'Comment', 'comments', 'CommentFields', null, true),
            new EntityQuery(Kind::Menu, 'Menu', 'menus', 'MenuFields', null, true),
            new EntityQuery(Kind::UrlRedirect, 'UrlRedirect', 'urlRedirects', 'UrlRedirectFields', null, true),
            new EntityQuery(Kind::DeliveryProfile, 'DeliveryProfile', 'deliveryProfiles', 'DeliveryProfileFields', null, false),
        ];
        $sources = [];
        foreach ($list as $source) {
            $sources[$source->kind->value] = $source;
        }

        return $sources;
    }

    public static function for(Kind $kind): EntityQuery
    {
        return self::all()[$kind->value] ?? throw new SourceError("{$kind->value} has no Shopify list source");
    }
}
