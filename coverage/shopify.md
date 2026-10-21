<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Shopify Admin API 2026-10 → WordPress + WooCommerce + WooCommerce Square

The single source of truth for what the importer mirrors. Every top-level Shopify Admin data entity is
listed. **Mirrored** means the importer writes it into a native WordPress/WooCommerce home. **Gap** means
WordPress + WooCommerce (+ the WooCommerce Square plugin) have no schema for it; the gap column names what
would be needed. Gaps are decisions for the operator, tracked on the Deck card.

Access scopes (read-only), each for the entities it unlocks:

| Scope | Read for |
|---|---|
| `read_products` | Products, variants, product media, collections |
| `read_inventory` | Inventory items (cost, weight, origin, HS code), stock quantities, inventory webhooks |
| `read_files` | Files |
| `read_customers` | Customers and their addresses |
| `read_discounts` | Code and automatic discounts |
| `read_orders` | Orders, refunds, transactions, fulfillments, order timeline (last 60 days) |
| `read_all_orders` | Orders older than 60 days. Granted by Shopify on request only; the Dev Dashboard doesn't offer it |
| `read_draft_orders` | Draft orders |
| `read_content` | Pages, blogs, articles, comments |
| `read_online_store_navigation` | Menus, URL redirects |
| `read_shipping` | Delivery profiles |
| `read_legal_policies` | Shop policies. Read on their own: without the scope the shop settings still mirror and the run warns that policies were skipped, leaving existing policy pages as they are |

Query cost: Shopify refuses before running it any query whose requested cost is over 1,000 points. Every
page size the importer asks for (the page itself and each nested connection it pages through) is a query
variable; a refused query is resent at once with every size scaled down by how far over it was (never below
1), the sizes that fit are kept for the rest of the run, and they grow back toward the defaults (50 per
page, 250 per nested page) while responses report room under the limit. `composer validate-graphql` checks
that every operation fits at page size 1 and that its cost estimate matches the costs recorded from a live
store (`tests/fixtures/shopify-query-cost.json`, re-recorded with `tools/calibrate-query-cost.php`).

Rate limits: Shopify's GraphQL Admin API meters a query-cost bucket and reports it on every response. Before
each query the importer waits until the bucket can take one as costly as the last, so it doesn't run into
the throttle; a throttled query is retried once the bucket refills (README, "How it stays idempotent", for
pauses and the credential hold).

## Mirrored

| Shopify | WordPress / WooCommerce home | Notes |
|---|---|---|
| Shop name, contact email, currency, weight unit, address, timezone, taxes-included | `blogname`, `admin_email`-adjacent store email, `woocommerce_currency`, `woocommerce_weight_unit`, store address options, `timezone_string`, `woocommerce_prices_include_tax` | Opt-in (`store_settings`); off by default because it changes site configuration. |
| Shop policies (refund, shipping, privacy, terms of service/sale, legal notice, contact, subscription) | Pages; privacy → `wp_page_for_privacy_policy`, terms → `woocommerce_terms_page_id`, refund → Woo refund/returns page | |
| Files (images, videos, generic files) | Media library attachments | De-duplicated by source URL. |
| Collections (custom and smart) | `product_cat` terms: name, slug (handle), description, image, SEO title/description, sort order (meta) | Smart-collection rules go to [wp-woo-dynamic-collections](../wp-woo-dynamic-collections) (`_wdc_rules` term meta, Shopify's shape), which keeps membership current after cutover; the importer also supplies the taxonomy category and metafields those rules test (`wdc_product_extra_facts`). |
| Products | Simple or variable products: title, slug, description, short description (none in Shopify), status (ACTIVE→publish, DRAFT→draft, ARCHIVED→private, UNLISTED→publish + catalog visibility hidden), tags → `product_tag`, vendor → `product_brand` (core Brands), product type → global attribute, category (standard taxonomy) → meta, template suffix → meta, SEO title/description, publish date | |
| Product options + values | Global attributes (`pa_*`) + terms, used for variations | Option order preserved. |
| Variants | Variations: SKU, barcode → GTIN (`global_unique_id`), price/compare-at → sale/regular price, cost → Cost of Goods, weight + unit, requires shipping → virtual, taxable → tax status, inventory tracked/quantity/policy → manage stock/stock/backorders, image, position | Total quantity across Shopify locations. |
| Product media | Featured image + gallery | Videos/3D go to the media library and product meta (see Gaps). |
| Inventory item origin (country, province, HS code) | Product/variation meta | No native field. |
| Metafields (every owner) | Post/term/user/order meta `_mirror_mf_<namespace>_<key>`, with type kept alongside | Values as Shopify serialises them. |
| Customers | WooCommerce customers (WP users, role `customer`): names, email, phone, locale → user locale, note → biography, tags, state, tax exempt, verified email, email/SMS marketing consent (meta), created date | Adopts an existing user with the same email. Passwords can't be exported from Shopify (see Gaps). |
| Customer default address | Billing + shipping address | Extra addresses kept as meta (see Gaps). |
| Code discounts — basic (percentage/fixed, all/products/collections, once per customer, usage limit, dates, minimum subtotal, specific customers) | Coupons: percent / fixed_cart / fixed_product, product + category restrictions, usage limits, expiry, minimum spend, email restrictions | Every redeem code becomes its own coupon. |
| Code discounts — free shipping (minimum subtotal, usage, dates) | Coupons with free shipping | Max shipping price and destination selection → meta. |
| Orders | WooCommerce orders (HPOS-safe): number (Shopify name kept as order number), dates, status, currency, customer or guest, billing/shipping, line items (product/variation link, SKU, quantities, prices, line taxes, discounts), shipping lines with taxes, fees (additional fees, duties, tips), tax lines, discount codes as coupon lines, totals as Shopify computed them, payment gateway, transactions → transaction id + notes, custom attributes → meta, note → customer note, tags → meta, cancel reason, risk level, source | No emails sent; no stock or coupon-usage side effects. |
| Refunds | Woo refunds (amount, reason, refunded line items + quantities, date) | No money moves. |
| Fulfillments (status, tracking company/number/URL) | Order meta + one order note per fulfillment | Customer-facing tracking needs a plugin (see Gaps). |
| Order timeline comments | Order notes (private) | |
| Draft orders | Woo orders with status `pending` (invoice sent/open); completed drafts are left to their real order | |
| Pages | Pages: title, slug, body (embedded images localised), published state/date, template suffix (meta), SEO | |
| Blogs | Post categories | |
| Articles | Posts: title, slug, body, summary → excerpt, featured image, tags, author (matched to a WP user by display name, else the configured default author; name kept as meta), published state/date, SEO | |
| Comments | Post comments: author name/email, body, status (published → approved, pending/unapproved → held, spam → spam, removed → trash), dates | |
| Menus | WordPress navigation menus, nested items, links resolved to the mirrored product/category/page/post | |
| URL redirects | 301 redirects served by the plugin | Plus automatic 301s from every old Shopify URL (`/products/…`, `/collections/…`, `/pages/…`, `/blogs/…`) to its new home. |
| Delivery profiles (zones, flat rates, free-over-subtotal) | WooCommerce shipping zones with flat-rate and free-shipping methods | Opt-in (`shipping_zones`). Weight/price-banded rates: see Gaps. |
| Product reviews (no Shopify API — exported CSV from Judge.me or Shopify Product Reviews) | Product reviews (comments of type `review` with rating, verified flag) | `wp idempotent-import reviews <file.csv>`. |

## WooCommerce Square compatibility

The WooCommerce Square plugin syncs the WooCommerce catalog to Square; it needs a SKU on every product and
variation and caps an item at 250 variations. The importer reports every product that breaks either rule,
and can generate a stable SKU (`shopify-<variant id>`) for variants that have none (`generate_missing_skus`,
off by default). Keep Square's system of record on WooCommerce while the importer runs.

## Gaps (no WordPress + WooCommerce + Square schema)

| Shopify | What it would take |
|---|---|
| Gift cards and their balances | Square Gift Cards, through the gift card writer the BigCommerce adapter already uses. Not built for Shopify yet: Shopify's API never reveals a card's code (only its last characters), so each card needs a new number (an operator decision: customers must be told), and its new GraphQL needs costs recorded from the live store (`tools/calibrate-query-cost.php`), which needs the operator's go-ahead. |
| Store credit accounts | A store-credit/wallet plugin. |
| Customer passwords | Not exportable from Shopify; customers reset on first login (`wp idempotent-import send-password-resets` at cutover). |
| Saved customer payment methods | Not exportable (PCI). |
| Additional customer addresses (beyond the default) | Kept as meta; an address-book plugin to use them at checkout. |
| Marketing consent acting on newsletters | Kept as meta; a newsletter plugin (MailPoet etc.) to import it. |
| Automatic discounts, Buy X Get Y, app discounts, minimum-quantity requirements, segment/market-targeted discounts | A dynamic-pricing / BOGO plugin. |
| Product bundles / components, combined listings | WooCommerce Product Bundles / Composite Products. |
| Selling plans, subscription contracts, billing attempts | WooCommerce Subscriptions. |
| Multi-location inventory, locations, transfers, purchase orders, shipments | A multi-inventory plugin (WooCommerce Square syncs one Square location). |
| Product video and 3D media in the product gallery | A product-video gallery plugin (files are in the media library). |
| Fulfillment tracking shown to customers | A shipment-tracking plugin (data is on the order). |
| Returns, return policies, reverse fulfilment | A returns (RMA) plugin. |
| Weight- or price-banded shipping rates, carrier-calculated rates | Table Rate Shipping / carrier plugins. |
| Tax configuration (Shopify calculates tax automatically) | Configure WooCommerce tax rates or WooCommerce Tax; order tax lines are mirrored. |
| Translations, markets, multiple currencies, price lists, catalogs | WPML/Polylang + a multi-currency plugin. |
| B2B companies, company locations, payment terms | A B2B plugin. |
| Metaobjects and metafield definitions | A custom post type per metaobject definition (custom code). |
| Staff accounts | Deliberately not mirrored (access control); create WordPress users by hand. |
| Abandoned checkouts | An abandoned-cart plugin. |
| Customer segments, saved searches, marketing activities/events, analytics | No equivalent. |
| Themes, script tags, pixels, checkout branding, Shopify Functions, apps | Platform-specific; rebuilt, not migrated. |
| Shopify Payments payouts, disputes, balance transactions | Stay in Shopify Payments. |
