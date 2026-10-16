<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# wp-woo-idempotent-importer

A WordPress plugin that keeps a WooCommerce store an exact, one-way copy of another store until you cut
over. Run it as often as you like: what's new at the source is created, what changed is updated, what
was deleted is moved to the trash, and what didn't change isn't touched. A re-run against an unchanged
store writes nothing at all.

Each source platform is an adapter over the same engine:

| Source | Status | What it mirrors |
|---|---|---|
| Shopify | Built | Products and variants, collections, customers, discount codes, orders with refunds and fulfillments, draft orders, files, pages, blogs, articles, comments, menus, URL redirects, shop policies, store settings, shipping zones, and reviews from a review app's CSV export — [coverage/shopify.md](coverage/shopify.md) |
| Etsy | Built | The shop and its policies, sections, listings in every state with variations, buyers, receipts with refunds and shipments, and reviews — [coverage/etsy.md](coverage/etsy.md) |
| Square Online | Built | The seller's store settings and return policy, catalog categories, items with variations, options, images and main-location stock, customers, and orders with refunds and fulfillments — [coverage/square_online.md](coverage/square_online.md) |
| BigCommerce | Built | Store settings, categories, products with variants, images, brands and custom fields, customers, coupons and coupon promotions, orders with taxes, shipping, refunds and shipments, content pages, the blog and its posts, storefront redirects, and product reviews — [coverage/bigcommerce.md](coverage/bigcommerce.md) |
| Squarespace, Wix, Ecwid, Magento | Not built yet | Listed (so the choice exists) but refuse to run |

Each coverage page lists everything the source has, and what WordPress can't hold.

## How it stays idempotent

Every mirrored object is linked to its identity at the source (`gid://<source>/<Type>/<id>`), with a
hash of exactly what was written. A run maps each source object to the fields it owns, hashes them, and
writes only when the hash changed or the WordPress copy was deleted by hand. Only mapped fields are
written, so anything else edited in WordPress is kept. Fields with no native WordPress home are kept as
`_mirror_*` meta, so nothing is dropped.

- **Incremental runs** (every hour by default) read only what changed since the last clean run, where
  the source can say (Shopify: everything; Etsy: receipts, buyers and reviews; Square Online:
  everything, including stock changes; BigCommerce: products, customers and orders).
- **Full runs** (daily by default) read everything and trash the copies of objects the source no longer
  has. A source's runs only ever retire that source's copies.
- **Webhooks** (Shopify) re-read one object as soon as it changes, signed with your app's client secret.

Mirroring history has no side effects: no customer or admin emails, no stock changes, no coupon usage.
Old Shopify URLs (`/products/…`, `/collections/…`, `/pages/…`, `/blogs/…`, `/policies/…`, and your
Shopify URL redirects) answer with a 301 to the new page, as do old BigCommerce storefront URLs (products,
categories, pages, blog posts and the store's 301 redirects).

## Setup

1. Install and activate WooCommerce (9.6 or later), then this plugin (a release zip includes `vendor/`;
   from source, run `composer install --no-dev`).
2. In WooCommerce → Store import, choose the source and save, then connect it:
   - **Shopify:** create a custom app with read access to products, inventory, customers, orders (plus
     `read_all_orders` for orders older than 60 days), draft orders, discounts, files, content (pages and
     blogs), navigation, online store, shipping and locales. Install it, then enter the store's
     `name.myshopify.com` domain and the Admin API token. For live updates, run
     `wp idempotent-import register-webhooks`.
   - **Etsy:** register an app at etsy.com/developers with the callback URL shown on the settings page,
     enter its keystring and shared secret, save, then click **Connect to Etsy** and approve read access
     as the shop owner.
   - **Square Online:** in the Square Developer Console, create an application and copy its production
     personal access token (or issue an OAuth access token with the read scopes listed in
     [coverage/square_online.md](coverage/square_online.md)), then enter it.
   - **BigCommerce:** under Settings → API, create a store-level API account with the read-only scopes
     listed in [coverage/bigcommerce.md](coverage/bigcommerce.md), then enter the store hash (the code
     after `/stores/` in its API path) and the access token.
3. Choose what to mirror and save. Secrets can instead be set in `wp-config.php` or the environment
   (`IIM_SHOPIFY_ACCESS_TOKEN`, `IIM_SHOPIFY_WEBHOOK_SECRET`, `IIM_ETSY_KEYSTRING`,
   `IIM_ETSY_SHARED_SECRET`, `IIM_SQUARE_ONLINE_ACCESS_TOKEN`, `IIM_BIGCOMMERCE_STORE_HASH`,
   `IIM_BIGCOMMERCE_ACCESS_TOKEN`); Etsy's refresh token is kept by the connect flow, since Etsy replaces it
   on every use.
4. Run a dry run, then a full run, from that page or `wp idempotent-import run --full`.

If you use WooCommerce Square, keep its system of record on WooCommerce while importing. The importer
warns about products Square can't sync (a variant without a SKU, or more than 250 variants).

## Commands

```sh
wp idempotent-import run [--full] [--dry-run] [--kinds=product,order]
wp idempotent-import status
wp idempotent-import sync <kind> <gid>
wp idempotent-import reset-cursors
wp idempotent-import send-password-resets [--yes]    # at cutover: no source exports passwords
wp idempotent-import clear --kinds=<kinds> [--yes]
wp idempotent-import reviews <export.csv> [--dry-run] # Shopify only
wp idempotent-import register-webhooks                # Shopify only
```

## Development

```sh
composer install          # also enables the pre-commit hook
composer schema           # cache Shopify's Admin GraphQL schema (needed by validate-graphql)
composer specs            # cache every REST source's published API spec (needed by validate-fixtures)
composer check            # lint, format, PHPStan (max), unit tests, GraphQL + REST fixture validation, audit
composer e2e              # WordPress + WooCommerce + MariaDB in podman, against a fake Shopify, Etsy and Square
```

Every Shopify GraphQL operation is validated against Shopify's published schema and every Shopify
fixture is executed against it; every Etsy fixture is validated strictly against Etsy's published
OpenAPI spec, and every Square fixture against Square's published OpenAPI spec. The queries and test data are proven against the real APIs without a live store.

## Credits

Shopify product mapping adapted from [S2W – Import Shopify to WooCommerce](https://wordpress.org/plugins/import-shopify-to-woocommerce/)
by VillaTheme (GPL-2.0-or-later). See [NOTICE](NOTICE).

## License

[AGPL-3.0-or-later](LICENSE).
