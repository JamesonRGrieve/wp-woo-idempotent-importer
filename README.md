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
| Squarespace | Built | Store settings, store pages, products with variants, images and stock, customers, promo-code discounts, and orders with payments, taxes, refunds and fulfillments — [coverage/squarespace.md](coverage/squarespace.md) |
| Wix | Built | Site settings, the Stores catalog (V3 or V1) with categories or collections, products with variants, images and inventory, contacts, coupons, orders with payments, taxes, refunds and fulfillments, the blog with its categories and posts, URL redirects, and product reviews — [coverage/wix.md](coverage/wix.md) |
| Magento / Adobe Commerce | Built | Store settings, categories, simple, virtual, downloadable and configurable products with their child products, images and stock, customers, cart price rules with coupons, orders with taxes, credit memos and shipments, and CMS pages — [coverage/magento.md](coverage/magento.md) |
| Lightspeed eCom (C-Series) | Built | Shop settings, categories, products with variants, images, stock and brands, customers, discount codes, orders with taxes, credit notes and shipments, text pages, blogs and their articles, redirects, and product reviews — [coverage/lightspeed.md](coverage/lightspeed.md) |
| Ecwid (Lightspeed) | Built | Store settings and legal pages, categories, products with variations, images, stock and brands, customers, discount coupons, orders with taxes, refunds and tracking, Instant Site redirects, and product reviews — [coverage/ecwid.md](coverage/ecwid.md) |

Each coverage page lists everything the source has, and what WordPress can't hold.

## How it stays idempotent

Every mirrored object is linked to its identity at the source (`gid://<source>/<Type>/<id>`), with a
hash of exactly what was written. A run maps each source object to the fields it owns, hashes them, and
writes only when the hash changed or the WordPress copy was deleted by hand. Only mapped fields are
written, so anything else edited in WordPress is kept. Fields with no native WordPress home are kept as
`_mirror_*` meta, so nothing is dropped.

- **Incremental runs** (every hour by default) read only what changed since the last clean run, where
  the source can say (Shopify: everything; Etsy: receipts, buyers and reviews; Square Online:
  everything, including stock changes; BigCommerce: products, customers and orders; Squarespace:
  products and orders; Wix: contacts and orders, and on a V3 catalog categories and products, including
  stock changes; Magento: customers and orders; Lightspeed eCom: products (including variant changes),
  customers and orders (including shipments and credit notes); Ecwid: products, customers, discount
  coupons, orders and reviews).
- **Full runs** (daily by default) read everything and trash the copies of objects the source no longer
  has. A source's runs only ever retire that source's copies.
- **Webhooks** (Shopify) re-read one object as soon as it changes, signed with your app's client secret.

The first import of a large store can take hours, so it is careful not to get your API key locked out:

- Requests are paced under each source's documented rate limit. Where the source reports how many
  requests are left, it waits for the window to reset before using the last one.
- Rate limiting and server errors are retried with exponential backoff (1, 2, 4, 8, 16 seconds).
- When the source keeps pushing back, or asks for a long wait, the run pauses exactly where it is and
  carries on later. The pauses grow from a minute to an hour and hold on for about a day, long enough to
  outlast a spent daily quota; only then is that part of the run given up and retried next run.
- If the source rejects the credentials, the run stops at once. Scheduled runs and webhooks then make
  no further calls until you save the settings or start a run yourself, so a bad or revoked key isn't
  retried every hour. The settings page and `wp idempotent-import status` say when this has happened.

Mirroring history has no side effects: no customer or admin emails, no stock changes, no coupon usage.
Old Shopify URLs (`/products/…`, `/collections/…`, `/pages/…`, `/blogs/…`, `/policies/…`, and your
Shopify URL redirects) answer with a 301 to the new page, as do old BigCommerce storefront URLs (products,
categories, pages, blog posts and the store's 301 redirects), old Squarespace product and store page URLs,
and old Wix product pages, blog posts and blog categories, and the site's URL redirects, and old Magento
product, category (`.html`) and CMS page URLs, and old Lightspeed eCom product (`.html`), category, text
page, blog and article URLs and the shop's redirects, and old Ecwid product and category URLs and the
store's Instant Site redirects.

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
   - **Squarespace:** under Settings → Developer API keys, generate a key with the read-only permissions
     listed in [coverage/squarespace.md](coverage/squarespace.md), then enter it. Pages and blog posts have
     no API; bring them over with the site's WordPress export.
   - **Wix:** in the account's API Keys Manager, generate an API key with the read permissions listed in
     [coverage/wix.md](coverage/wix.md), then enter it with the site id (from the site's dashboard URL).
     Site pages have no API; bring them over by hand.
   - **Magento / Adobe Commerce:** under System → Extensions → Integrations, add an integration with the
     read access listed in [coverage/magento.md](coverage/magento.md) and activate it. Set Stores →
     Configuration → Services → OAuth → Consumer Settings → "Allow OAuth Access Tokens to be used as
     standalone Bearer tokens" to Yes, then enter the store URL, the store view code (blank for `default`)
     and the integration's access token.
   - **Lightspeed eCom (C-Series):** in the back office, create an API key with the read permissions
     listed in [coverage/lightspeed.md](coverage/lightspeed.md), then enter its key and secret, the shop's
     cluster (`eu1` or `us1`) and the language code whose content to mirror (e.g. `en`, `nl`, `us`).
     Lightspeed allows 3,000 requests an hour and 12,000 a day, so a first import is paced at one request
     every 1.2 seconds, and a store needing more than a day's quota carries on as the quota frees up.
   - **Ecwid (Lightspeed):** in the Ecwid admin, create a custom app with the read scopes listed in
     [coverage/ecwid.md](coverage/ecwid.md), then enter the store ID and the app's secret access token.
     Ecwid allows 600 requests a minute, so requests are paced at one every 0.1 seconds. Instant Site pages
     have no content API; bring them over by hand.
3. Choose what to mirror and save. Secrets can instead be set in `wp-config.php` or the environment
   (`IIM_SHOPIFY_ACCESS_TOKEN`, `IIM_SHOPIFY_WEBHOOK_SECRET`, `IIM_ETSY_KEYSTRING`,
   `IIM_ETSY_SHARED_SECRET`, `IIM_SQUARE_ONLINE_ACCESS_TOKEN`, `IIM_BIGCOMMERCE_STORE_HASH`,
   `IIM_BIGCOMMERCE_ACCESS_TOKEN`, `IIM_SQUARESPACE_API_KEY`, `IIM_WIX_API_KEY`, `IIM_WIX_SITE_ID`, `IIM_MAGENTO_STORE_URL`,
   `IIM_MAGENTO_STORE_CODE`, `IIM_MAGENTO_ACCESS_TOKEN`, `IIM_LIGHTSPEED_CLUSTER`, `IIM_LIGHTSPEED_LANGUAGE`,
   `IIM_LIGHTSPEED_API_KEY`, `IIM_LIGHTSPEED_API_SECRET`, `IIM_ECWID_STORE_ID`, `IIM_ECWID_SECRET_TOKEN`); Etsy's refresh token is kept by the connect flow, since Etsy replaces it
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
composer e2e              # WordPress + WooCommerce + MariaDB in podman, against a fake store of every built source
```

Every Shopify GraphQL operation is validated against Shopify's published schema and every Shopify
fixture is executed against it; every REST source's fixture is validated against that source's
published OpenAPI spec (strictly for Etsy, whose API returns every declared property). The queries and
test data are proven against the real APIs without a live store.

## Credits

Shopify product mapping adapted from [S2W – Import Shopify to WooCommerce](https://wordpress.org/plugins/import-shopify-to-woocommerce/)
by VillaTheme (GPL-2.0-or-later). See [NOTICE](NOTICE).

## License

[AGPL-3.0-or-later](LICENSE).
