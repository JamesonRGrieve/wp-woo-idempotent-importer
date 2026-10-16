<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# wp-woo-idempotent-shopify-importer

A WordPress plugin that keeps a WooCommerce store an exact, one-way copy of a Shopify store until you
cut over. Run it as often as you like: what's new in Shopify is created, what changed is updated, what
was deleted is moved to the trash, and what didn't change isn't touched. A re-run against an unchanged
store writes nothing at all.

It mirrors every Shopify object WordPress + WooCommerce + WooCommerce Square has a home for: products and
variants, collections, customers, discount codes, orders with refunds and fulfillments, draft orders,
files, pages, blogs, articles, comments, menus, URL redirects, shop policies, store settings and shipping
zones, plus product reviews from a review app's CSV export. [COVERAGE.md](COVERAGE.md) lists each one, and
everything Shopify has that WordPress can't hold.

## How it stays idempotent

Every mirrored object is linked to its Shopify ID, with a hash of exactly what was written. A run maps
each Shopify object to the fields it owns, hashes them, and writes only when the hash changed or the
WordPress copy was deleted by hand. Only mapped fields are written, so anything else edited in WordPress
is kept. Fields with no native WordPress home are kept as `_shopify_*` meta, so nothing is dropped.

- **Incremental runs** (every hour by default) read only objects updated since the last clean run.
- **Full runs** (daily by default) read everything and trash the copies of objects Shopify no longer has.
- **Webhooks** re-read one object as soon as it changes (signed with your app's client secret).

Mirroring history has no side effects: no customer or admin emails, no stock changes, no coupon usage.
Old Shopify URLs (`/products/…`, `/collections/…`, `/pages/…`, `/blogs/…`, `/policies/…`, and your
Shopify URL redirects) answer with a 301 to the new page.

## Setup

1. Install and activate WooCommerce (9.6 or later), then this plugin (a release zip includes `vendor/`;
   from source, run `composer install --no-dev`).
2. In Shopify, create a custom app with read access to products, inventory, customers, orders (plus
   `read_all_orders` for orders older than 60 days), draft orders, discounts, files, content (pages and
   blogs), navigation, online store, shipping and locales. Install it and copy the Admin API token.
3. In WooCommerce → Shopify import, enter the store's `name.myshopify.com` domain and the token, choose
   what to mirror, and save. Tokens can instead be set as `ISI_SHOPIFY_ACCESS_TOKEN` and
   `ISI_SHOPIFY_WEBHOOK_SECRET` in `wp-config.php` or the environment.
4. Run a dry run, then a full run, from that page or `wp shopify-import run --full`.
5. For live updates, `wp shopify-import register-webhooks`.

If you use WooCommerce Square, keep its system of record on WooCommerce while importing. The importer
warns about products Square can't sync (a variant without a SKU, or more than 250 variants).

## Commands

```sh
wp shopify-import run [--full] [--dry-run] [--kinds=product,order]
wp shopify-import status
wp shopify-import sync <kind> <gid>
wp shopify-import reviews <export.csv> [--dry-run]
wp shopify-import register-webhooks
wp shopify-import reset-cursors
wp shopify-import send-password-resets [--yes]    # at cutover: Shopify passwords can't be exported
wp shopify-import clear --kinds=<kinds> [--yes]
```

## Development

```sh
composer install          # also enables the pre-commit hook
composer schema           # cache Shopify's Admin GraphQL schema (needed by validate-graphql)
composer check            # lint, format, PHPStan (max), unit tests, GraphQL + fixture validation, audit
composer e2e              # WordPress + WooCommerce + MariaDB in podman, against a fake Shopify
```

Every GraphQL operation is validated against Shopify's published schema, and every recorded fixture is
executed against it, so the queries and test data are proven against the real API without a live store.

## Credits

Product mapping adapted from [S2W – Import Shopify to WooCommerce](https://wordpress.org/plugins/import-shopify-to-woocommerce/)
by VillaTheme (GPL-2.0-or-later). See [NOTICE](NOTICE).

## License

[AGPL-3.0-or-later](LICENSE).
