<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# wp-woo-idempotent-importer

A WordPress plugin that keeps a WooCommerce store an exact, one-way copy of another store until you cut
over, and does the same for a CRM (into FluentCRM) or an accounting system. Run it as often as you like:
what's new at the source is created, what changed is updated, what was deleted is moved to the trash, and
what didn't change isn't touched. A re-run against an unchanged source writes nothing at all.

Each source platform is an adapter over the same engine:

| Source | Status | What it mirrors |
|---|---|---|
| Shopify | Built | Products and variants, collections, customers, discount codes, orders with refunds and fulfillments, draft orders, files, pages, blogs, articles, comments, menus, URL redirects, shop policies, store settings, shipping zones, and reviews from a review app's CSV export — [coverage/shopify.md](coverage/shopify.md) |
| Etsy | Built | The shop and its policies, sections, listings in every state with variations, buyers, receipts with refunds and shipments, and reviews — [coverage/etsy.md](coverage/etsy.md) |
| Square Online | Built | The seller's store settings and return policy, catalog categories, items with variations, options, images and main-location stock, customers, and orders (online and at the counter) with refunds, returns, exchanges and fulfillments — [coverage/square_online.md](coverage/square_online.md) |
| BigCommerce | Built | Store settings, categories, products with variants, images, brands and custom fields, customers, coupons and coupon promotions, orders with taxes, shipping, refunds and shipments, content pages, the blog and its posts, storefront redirects, and product reviews — [coverage/bigcommerce.md](coverage/bigcommerce.md) |
| Squarespace | Built | Store settings, store pages, products with variants, images and stock, customers, promo-code discounts, and orders with payments, taxes, refunds and fulfillments — [coverage/squarespace.md](coverage/squarespace.md) |
| Wix | Built | Site settings, the Stores catalog (V3 or V1) with categories or collections, products with variants, images and inventory, contacts, coupons, orders with payments, taxes, refunds and fulfillments, the blog with its categories and posts, URL redirects, and product reviews — [coverage/wix.md](coverage/wix.md) |
| Magento / Adobe Commerce | Built | Store settings, categories, simple, virtual, downloadable and configurable products with their child products, images and stock, customers, cart price rules with coupons, orders with taxes, credit memos and shipments, and CMS pages — [coverage/magento.md](coverage/magento.md) |
| Lightspeed eCom (C-Series) | Built | Shop settings, categories, products with variants, images, stock and brands, customers, discount codes, orders with taxes, credit notes and shipments, text pages, blogs and their articles, redirects, and product reviews — [coverage/lightspeed.md](coverage/lightspeed.md) |
| Ecwid (Lightspeed) | Built | Store settings and legal pages, categories, products with variations, images, stock and brands, customers, discount coupons, orders with taxes, refunds and tracking, Instant Site redirects, and product reviews — [coverage/ecwid.md](coverage/ecwid.md) |
| Webflow | Built | Site settings, assets, e-commerce categories, products with their SKUs and inventory, buyers, orders with refunds, static pages, the blog collection and its posts, every other CMS collection as a custom post type with its items, and 301 redirects — [coverage/webflow.md](coverage/webflow.md) |
| HubSpot | Built | Into FluentCRM: companies, contact lists and segments, contacts with their companies, lists and custom properties, and their notes, calls, emails and meetings. Into WooCommerce: the product library, buyers and commerce orders. Deals, pipelines and tasks are planned — [coverage/hubspot.md](coverage/hubspot.md) |
| Salesforce | Built | Into FluentCRM: accounts, campaigns (as lists), topics (as tags), contacts and leads with their custom fields, and their logged calls, emails, events and notes. Into WooCommerce: products at their standard price, buyers and orders. Opportunities and tasks are planned — [coverage/salesforce.md](coverage/salesforce.md) |
| Pipedrive | Built | Into FluentCRM: organizations, person filters (as lists), labels (as tags), persons with their custom fields, and their notes and done activities. Into WooCommerce: product categories and products with their variations. Deals and pending activities are planned — [coverage/pipedrive.md](coverage/pipedrive.md) |
| Zoho CRM | Built | Into FluentCRM: accounts, campaigns (as lists), tags, contacts and unconverted leads with their custom fields, and their notes, calls and meetings. Into WooCommerce: product categories, products, the contacts sales orders name, and sales orders with their items. Deals and tasks are planned — [coverage/zoho.md](coverage/zoho.md) |
| FreshBooks | Built | Items and services as products, clients as WooCommerce customers and FluentCRM contacts (their organizations as companies), and invoices with their payments as orders. The books (ledger, journals, expenses, bills), credit notes and estimates are operator decisions — [coverage/freshbooks.md](coverage/freshbooks.md) |

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
- **Webhooks** (Shopify, Square) re-read one object as soon as it changes: Shopify's signed with your
  app's client secret, Square's with the subscription's signature key over the webhook URL and body.

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
store's Instant Site redirects, and old Webflow product, category, page, blog post and CMS item URLs and the
site's 301 redirects.

## Setup

1. Install and activate WooCommerce (9.6 or later), then this plugin (a release zip includes `vendor/`;
   from source, run `composer install --no-dev`). To mirror a CRM, or an accounting system's clients, also
   install and activate FluentCRM (free, wordpress.org/plugins/fluent-crm); its writes never start a FluentCRM
   automation or email.
2. In WooCommerce → Store import, choose the source and save, then connect it:
   - **Shopify:** in Shopify's Dev Dashboard (dev.shopify.com, signed in to the store's organization),
     create an app with these access scopes (paste the line into the version's scopes):

     ```text
     read_products, read_inventory, read_files, read_customers, read_discounts, read_orders, read_draft_orders, read_content, read_online_store_navigation, read_shipping, read_legal_policies
     ```

     Release a version, install it on the store, then
     enter the store's `name.myshopify.com` domain and the app's client ID and client secret. The importer
     exchanges them for a 24-hour token and renews it itself. Orders older than 60 days need
     `read_all_orders`, which Shopify grants only on request and the Dev Dashboard doesn't offer, so without
     it only the last 60 days of orders are mirrored. Without `read_legal_policies` everything else still
     mirrors and the run warns that the shop's policies were skipped. A legacy custom app (made in the store admin before 2026) can use
     its Admin API access token instead. For live updates, run `wp idempotent-import register-webhooks`;
     Shopify signs them with the client secret.
   - **Etsy:** register an app at etsy.com/developers with the callback URL shown on the settings page,
     enter its keystring and shared secret, save, then click **Connect to Etsy** and approve read access
     as the shop owner.
   - **Square Online:** in the Square Developer Console, create an application and copy its production
     personal access token (or issue an OAuth access token with the read scopes listed in
     [coverage/square_online.md](coverage/square_online.md)), then enter it. For near real-time counter
     sales, add a webhook subscription in the same application pointing at the webhook URL shown on the
     settings page (events `order.created`, `order.updated`, `order.fulfillment.updated`,
     `payment.created`, `payment.updated`, `refund.created`, `refund.updated`, `customer.created`,
     `customer.updated`, `customer.deleted`) and enter its signature key. Square signs the URL exactly as
     registered; behind a proxy that rewrites it, set `IIM_WEBHOOK_URL` to the registered URL.
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
   - **Webflow:** generate a site API token with the read access listed in
     [coverage/webflow.md](coverage/webflow.md), or register a Webflow OAuth app with the callback URL shown
     on the settings page, enter its client ID and secret, save and click **Connect to Webflow**. Enter the
     site ID and, if the site has a blog, the ID of its CMS collection. Every other CMS collection becomes a
     custom post type. Webflow allows 60 requests a minute on smaller plans, so requests are paced at one a
     second.
   - **HubSpot:** create a private app with the read scopes listed in [coverage/hubspot.md](coverage/hubspot.md)
     and enter its access token, or register an OAuth app with the callback URL shown on the settings page,
     enter its client ID and secret and click **Connect to HubSpot**. HubSpot allows 100 requests every 10
     seconds, so requests are paced at ten a second.
   - **Salesforce:** create a connected app with OAuth (scopes `api` and `refresh_token`, PKCE) and the
     callback URL shown on the settings page, enter its consumer key and secret (and the login URL of a
     sandbox or My Domain, if not `login.salesforce.com`), save and click **Connect to Salesforce**. Salesforce
     counts requests per day, so a spent daily allowance pauses the run until it frees up.
   - **Pipedrive:** enter a personal API token, or register a Pipedrive app (scopes `contacts:read`,
     `activities:read`, `products:read`) with the callback URL shown on the settings page, enter its client ID
     and secret and click **Connect to Pipedrive**. Requests are paced at ten a second, under the lowest plan's
     burst limit.
   - **Zoho CRM:** register a server-based client in the Zoho API console with the callback URL shown on the
     settings page, enter its client ID and secret (and your data centre's accounts URL, if not
     `https://accounts.zoho.com`), save and click **Connect to Zoho CRM**. Zoho counts API credits per day, so a
     spent allowance pauses the run until it frees up.
   - **FreshBooks:** create an app in FreshBooks' developer portal (scopes `user:profile:read`,
     `user:clients:read`, `user:invoices:read`, `user:payments:read`, `user:billable_items:read`) with the
     callback URL shown on the settings page as its redirect URI, enter its client ID and secret (and the
     account ID, if your login has several businesses), save and click **Connect to FreshBooks**. Requests are
     paced at two a second.
3. Choose what to mirror and save. Secrets can instead be set in `wp-config.php` or the environment
   (`IIM_SHOPIFY_CLIENT_ID`, `IIM_SHOPIFY_CLIENT_SECRET`, or a legacy app's `IIM_SHOPIFY_ACCESS_TOKEN` and
   `IIM_SHOPIFY_WEBHOOK_SECRET`; `IIM_ETSY_KEYSTRING`,
   `IIM_ETSY_SHARED_SECRET`, `IIM_SQUARE_ONLINE_ACCESS_TOKEN`, `IIM_SQUARE_ONLINE_WEBHOOK_SIGNATURE_KEY`, `IIM_BIGCOMMERCE_STORE_HASH`,
   `IIM_BIGCOMMERCE_ACCESS_TOKEN`, `IIM_SQUARESPACE_API_KEY`, `IIM_WIX_API_KEY`, `IIM_WIX_SITE_ID`, `IIM_MAGENTO_STORE_URL`,
   `IIM_MAGENTO_STORE_CODE`, `IIM_MAGENTO_ACCESS_TOKEN`, `IIM_LIGHTSPEED_CLUSTER`, `IIM_LIGHTSPEED_LANGUAGE`,
   `IIM_LIGHTSPEED_API_KEY`, `IIM_LIGHTSPEED_API_SECRET`, `IIM_ECWID_STORE_ID`, `IIM_ECWID_SECRET_TOKEN`, `IIM_WEBFLOW_SITE_ID`, `IIM_WEBFLOW_BLOG_COLLECTION_ID`,
   `IIM_WEBFLOW_API_TOKEN`, `IIM_WEBFLOW_CLIENT_ID`, `IIM_WEBFLOW_CLIENT_SECRET`, `IIM_HUBSPOT_ACCESS_TOKEN`,
   `IIM_HUBSPOT_CLIENT_ID`, `IIM_HUBSPOT_CLIENT_SECRET`, `IIM_SALESFORCE_CLIENT_ID`, `IIM_SALESFORCE_CLIENT_SECRET`,
   `IIM_SALESFORCE_LOGIN_URL`, or a token issued elsewhere as `IIM_SALESFORCE_ACCESS_TOKEN` with `IIM_SALESFORCE_INSTANCE_URL`,
   `IIM_PIPEDRIVE_API_TOKEN`, `IIM_PIPEDRIVE_CLIENT_ID`, `IIM_PIPEDRIVE_CLIENT_SECRET`, `IIM_ZOHO_CLIENT_ID`,
   `IIM_ZOHO_CLIENT_SECRET`, `IIM_ZOHO_ACCOUNTS_URL`, `IIM_FRESHBOOKS_CLIENT_ID`, `IIM_FRESHBOOKS_CLIENT_SECRET`,
   `IIM_FRESHBOOKS_ACCOUNT_ID`); tokens from a connect flow (Etsy's refresh
   token, which Etsy replaces on every use, and every OAuth source's tokens) are kept by the plugin.
4. Run a dry run, then a full run, from that page or `wp idempotent-import run --full`.

If you use WooCommerce Square, keep its system of record on WooCommerce while importing. The importer
warns about products Square can't sync (a variant without a SKU, or more than 250 variants).

Keeping Square counter sales in WooCommerce (Square stays the store's system of record, as WooCommerce
Square's catalog and stock master): select Square Online, mirror orders, and add the webhook subscription
above. Counter sales become WooCommerce orders, returns and exchanges become refunds on the sales they
return from, and nothing is restocked (WooCommerce Square keeps stock). Orders WooCommerce Square itself
pushed to Square from WooCommerce checkouts are recognised by the Square order id it stores on the
WooCommerce order and never mirrored back. **None of this has run against the Square sandbox yet**: every
Square request, response and webhook the tests use is recorded and validated against Square's published
API specification.

## Gift cards, store credit and loyalty

Gift cards and store credit live in **Square Gift Cards**: one balance, spendable online (WooCommerce
Square redeems them) and at the Square counter. With the `gift_card` kind switched on, each source gift card
becomes a digital Square gift card:

- Its number is the source's own code where Square can take it (8 to 20 letters and digits, separators
  dropped). A code Square can't use gets a new 16-digit number, shown to the customer in My Account →
  Gift cards (switch "new numbers" off to hold such cards instead).
- Its balance is loaded when it is first created. After that only what the source changes is applied, so
  money spent in Square is never put back. A balance that went up at the source is held and reported
  unless you allow loading it, because Square charges a load fee on increases in the US, Canada and
  Australia. A drop bigger than what's left in Square (spent in both stores) is reported, never taken
  below zero. Disabled, expired or unpaid source cards carry no balance.
- Square can't backdate activity, so the card's history at its source, and every change made in Square,
  is kept in the importer's ledger and shown in the customer's My Account.
- It is linked to the customer's Square profile, found by email or created.

Loyalty lives in **Square Loyalty**. With the `loyalty_account` kind switched on (Wix Loyalty Program), each
account becomes the Square Loyalty account for the customer's phone number (Square enrols by phone), created
when missing and linked to their Square customer. Its points are added once; afterwards only what the source
changes is applied. A customer without a phone number Square can use (E.164, or a 10-digit number in a US or
Canadian store) is held and retried. The source history and every adjustment are its ledger, shown in My
Account. The seller needs a Square Loyalty subscription.

Set the Square account in `wp-config.php`: `IIM_SQUARE_STORED_VALUE_ACCESS_TOKEN` (scopes `GIFTCARDS_READ
GIFTCARDS_WRITE LOYALTY_READ LOYALTY_WRITE CUSTOMERS_READ CUSTOMERS_WRITE`), `IIM_SQUARE_STORED_VALUE_LOCATION_ID`, and
`IIM_SQUARE_STORED_VALUE_ENVIRONMENT=sandbox` to try it first. Sources: BigCommerce gift certificates and
customer store credit (Shopify and Wix hide their cards' codes; see their coverage pages).

Square's own route for third-party gift cards is a one-time import by Square Support; creating many
digital cards through the API "may trigger compliance limit or rate limit errors". **None of this has run
against the Square sandbox yet**: every Square request and response the tests use is recorded and
validated against Square's published API specification.

## Commands

```sh
wp idempotent-import run [--full] [--dry-run] [--kinds=product,order]
wp idempotent-import status
wp idempotent-import sync <kind> <gid>
wp idempotent-import reset-cursors
wp idempotent-import send-password-resets [--yes]    # at cutover: no source exports passwords
wp idempotent-import clear --kinds=<kinds> [--yes]
wp idempotent-import reviews <export.csv> [--dry-run] # Shopify only
wp idempotent-import register-webhooks                # Shopify (Square's subscription is made in its Developer Console)
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
