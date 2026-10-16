<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Squarespace (Commerce APIs) → WordPress + WooCommerce + WooCommerce Square

The single source of truth for what the Squarespace adapter mirrors. The adapter reads Squarespace's Commerce
APIs (website profile, store pages, products v2, profiles, discounts, orders and transactions), validated
against Squarespace's published OpenAPI 3.1.1 document (pinned by hash in `tools/SpecCache.php`). Every
store-owned entity of those APIs is listed. **Mirrored** means the importer writes it into a native
WordPress / WooCommerce home; fields with no native home are kept on the object as `_mirror_<field>` meta.
**Gap** means WordPress + WooCommerce (+ WooCommerce Square) have no schema for it, or Squarespace doesn't
expose it. Gaps are decisions for the operator, tracked on the Deck card.

Access: an API key (Settings → Developer API keys) with read-only permission for Orders, Products,
Inventory, Transactions, Profiles and Discounts (an OAuth access token with the matching read scopes works
too). It is sent as a bearer token, with the `User-Agent` Squarespace requires on every request. Squarespace
has webhooks, but the plugin's webhook endpoint is Shopify's; scheduled runs keep the mirror current.
Products and orders are incremental (Squarespace takes a modification window: after the last clean run and
before the run started); store pages, profiles and discounts have no modification filter and are read in
full (unchanged objects are skipped by hash). Full runs retire the copies of anything Squarespace no longer
has. Squarespace allows 300 requests a minute and refuses requests for a minute after a 429 (it sends no
Retry-After): requests are paced under 300 a minute, and a 429 pauses the run for at least that minute
(README, "How it stays idempotent", for pauses and the credential hold).

Money arrives as a MonetaryAmount (`value`, `currency`); the value is read exactly (a JSON number as the
decimal its JSON spelled, a decimal string as sent), never through float arithmetic.

## Mirrored

| Squarespace | WordPress / WooCommerce home | Notes |
|---|---|---|
| Website title, currency, timezone, measurement standard | `blogname`, `woocommerce_currency`, `timezone_string`, `woocommerce_weight_unit` (opt-in store settings) | Metric → kg, imperial → lbs. |
| Website URL, site id, language, location (country, region) | Shop details (hashed with the shop) | The website profile has no store email, street address or tax setting; those settings are left as they are. |
| Store pages | `product_cat` terms | Title, URL slug; the old store page URL (`/<slug>`) 301s to the term. The enabled flag is meta. |
| Products (physical, service, download) | Products | Visible → publish, hidden → private. Description HTML, URL slug, tags, SEO title and description, creation time, the store page as the category. Services and downloads are virtual. The old product URL (the path of the product's `url`, `/<store page>/p/<slug>`) 301s to the product. Type, store page, URL, attribute names and download file name are meta. |
| Variants | Simple product (one variant with no attributes) or variable product with global attributes and variations | A variant is identified by its Squarespace id (stable across edits), the id an order line names; a download has no variants and is keyed by its product id. Attributes in the product's attribute order. Base price; the sale price while the variant is on sale. SKU (optional generated `squarespace-<variant id>`), GTIN, variant image; MPN and shipping dimensions are meta. |
| Stock | Stock quantity, managed unless unlimited | Read from the product's variants (the counts the Inventory API also serves). |
| Variant weight | Weight in WooCommerce's units | Kilogram → kg, pound → lbs. |
| Product images | Media library, featured image + gallery | By position; alt text kept. |
| Profiles that are customers (have orders) or have an account | WooCommerce customers (`squarespace-<profile id>`) | Name, email, creation time, the profile's address (and its phone) as billing and shipping. Marketing consent and the customer/account flags are meta. Passwords are never available; customers reset theirs at cutover. |
| Promo-code discounts: percentage or amount off the order, free shipping, for any order or above a cart total; a percentage off one product | WooCommerce coupons (`percent`, `fixed_cart`, free shipping) | Start and end, site-wide use limit, once per customer, minimum cart total, the product restriction. Subscription and payment-plan settings are meta. |
| Orders (every payment state) | WooCommerce orders (HPOS-safe), Squarespace's totals | Number shown as `#<order number>`. Status: canceled → cancelled; refunded → refunded; failed → failed; fulfilled → completed; payment pending or not charged → pending; authorized → on-hold; otherwise (paid, partially paid, refund pending or failed) → processing. Prices include tax when `priceTaxInterpretation` is INCLUSIVE. Test-mode orders are not mirrored. |
| Order line items | Line items | Linked to the product and variant; unit price paid × quantity; option choices and product-form answers as item meta; gift cards flagged. |
| Shipping lines | Shipping lines | Method and amount; an order with a shipping total but no lines gets one for its shipping option. |
| Promo codes on the order | Coupon lines; the discount total | |
| Transaction taxes | Order-level tax lines (name, rate, amount) | Summed per tax name over the order's sale and shipping items; an order without transactions gets one "Tax" line from its tax total. |
| Payments | Paid date (the first payment), payment method title (gateway and card), transaction id (the gateway's) | |
| Refunds (on payments) | WooCommerce refunds (no money moves, nothing restocked) | Amount and date. |
| Fulfillments | A private order note each, once | Carrier, service, tracking number and link. |
| Internal notes | A private order note each, once | |
| Channel, external order reference, payment and fulfillment state, subtotal, refunded total, checkout form answers, shipping option name and service type | Order meta | |

## Gaps

| Squarespace | Why not mirrored | What it would need |
|---|---|---|
| Pages, blog posts, galleries, site styling, navigation | No public API | The site's WordPress export (Settings → Import & export), imported with the WordPress importer |
| URL mappings (the site's 301 redirects) | No API | Re-entering them as redirects on the WordPress side |
| Product reviews | Not in the API | — |
| Gift card products and gift card balances | WooCommerce has no gift cards (reported as unsupported) | A gift-card extension |
| Download files | The API names the file but doesn't serve it; the product is mirrored virtual without a download (a warning is logged) | Uploading the files by hand |
| Automatic discounts, buy-X-get-Y discounts, an amount off or free shipping for one product, free shipping limited to pickup, local delivery or one shipping option | WooCommerce applies discounts only through coupons, which can't express these (reported as unsupported) | A dynamic-pricing plugin |
| Mailing-list subscribers and donors (profiles with no orders and no account) | Not customers; mapped to nothing | A newsletter plugin |
| Contacts API (contacts and address books) | Profiles carry the same customers; the Contacts API isn't read | — |
| Order line taxes | Squarespace's transactions don't say which order line a sale item is, so taxes are order-level lines | — |
| Refund line items and reasons | Squarespace reports refunds as amounts on payments | — |
| Fulfillment and note ids | Squarespace gives none; each is identified by its order and position | — |
| Store page descriptions, images and SEO | Not in the API | — |
| State and province | Kept as sent | — |
| Tax classes, product subscriptions, product forms beyond a line's answers | No WooCommerce schema, or not in the API | Dedicated extensions |
| Stock changed by a sale between full runs | Incremental runs read products by modification time; if a sale doesn't change a product's `modifiedOn` (unverified), its new stock lands on the next full run | — |
| Transaction summaries (order counts and totals per profile), redemption counts, discount status | Volatile statistics; would rewrite objects on every order | — |
| Squarespace webhooks | The plugin's webhook endpoint verifies Shopify's signatures only | A Squarespace webhook receiver |

Published-spec discrepancies (the fixtures follow the spec; the mapper reads both forms):

| Spec says | Real API (per Squarespace's docs and examples) | Effect |
|---|---|---|
| A MonetaryAmount's `currency` is an object (`currencyCode`, `symbol`, …) | A bare ISO 4217 code (`"USD"`) | Both are read. |
| A MonetaryAmount's `value` is a number | The docs show a JSON number too; a decimal string is accepted in case the live API sends one | Both are read exactly. |

Unverified against a live site (no Squarespace account was available): the discount `criteria`, `template`
and `trigger` type values (taken from the list filter's enums: `ANY_ORDER`, `CART_TOTAL`, `PRODUCT`,
`PERCENTAGE`, `FIXED_AMOUNT`, `FREE_SHIPPING`, `CODE`, `AUTO`); that an order's `customerId` is its profile's id
(the reader only links an order to a profile it can read by that id); that the unfiltered products list
includes every product type; that a 404 for a batch of profile ids means one of them is gone (the reader
then reads them one at a time); and that a percentage discount limited to one product discounts only that
product, as Squarespace's product discounts do in its admin.
