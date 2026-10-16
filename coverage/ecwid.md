<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Ecwid (REST API v3) → WordPress + WooCommerce + WooCommerce Square

The single source of truth for what the Ecwid adapter mirrors. The adapter reads Ecwid by Lightspeed's REST
API v3 (docs.ecwid.com/api-reference/rest-api/; Ecwid is also sold as Lightspeed eCom E-Series). Ecwid
publishes no OpenAPI document, so the responses the adapter reads are transcribed by hand from the official
reference pages into `tests/schemas/ecwid.json` (each schema cites its page), and the fixtures are validated
against it. **Mirrored** means the importer writes it into a native WordPress / WooCommerce home; fields with
no native home are kept on the object as `_mirror_<field>` meta. **Gap** means WordPress + WooCommerce (+
WooCommerce Square) have no schema for it, or the adapter doesn't read it. Gaps are decisions for the
operator, tracked on the Deck card.

## Access

A custom app created in the Ecwid admin, with the access scopes the reference names for each read:
`read_store_profile`, `read_catalog`, `read_customers`, `read_orders`, `read_discount_coupons` and
`read_reviews`, and, for Instant Site redirects, `manage_instant_site` (Ecwid documents no read-only scope
for them; leave redirects out of the mirrored kinds rather than grant it, since a missing scope is refused
with a 403, which stops the run like a rejected token). The operator enters the store ID and the app's secret access token
(`secret_…`, sent as `Authorization: Bearer`; it doesn't expire, so there is nothing to renew). Requests go
to `https://app.ecwid.com/api/v3/<store id>/`. Webhooks belong to an app's own endpoint and the plugin's
webhook endpoint is Shopify's; scheduled runs keep the mirror current.

Ecwid accepts up to 600 requests per minute per token; past that it refuses every request with a 429 and a
`Retry-After` cool-down (docs.ecwid.com/api-reference/rest-api/rest-api-overview). Requests are paced under
the limit (one every 0.1 seconds) and a 429 waits out its `Retry-After` (a longer wait pauses the run). The
page names no rate-limit headers, so pacing is by rate alone. Ecwid blocks a token for longer when requests
keep coming with a rejected token (after 20 a minute), so a rejected token (401, or 403 `INVALID_API_TOKEN`)
stops the run at once and holds scheduled runs (README, "How it stays idempotent"). Lists are paged 100 at a
time (`offset`, `limit`) and carry their objects whole (products with their variations and images, orders with
their items, refunds and shipping labels), so a first import costs about one request per 100 objects of each
kind, plus the store profile.

Products, customers, discount coupons, orders and reviews are incremental (`updatedFrom`, sent as a UNIX
timestamp, so there is no zone to allow for). Categories and Instant Site redirects are read in full
(unchanged objects are skipped by hash). Ecwid also lists deleted ids (`/<entity>/deleted`); they aren't
read, because full runs already retire the copies of anything Ecwid no longer has.

Money arrives as JSON numbers; they are read exactly (as the decimal their JSON spelled), never through
float arithmetic. Timestamps (`2024-09-04 07:20:11 +0000`) are converted to UTC.

## Mirrored

| Ecwid | WordPress / WooCommerce home | Notes |
|---|---|---|
| Store profile (`/profile`): store name (else company name), company email, currency, weight unit, timezone, gross or net prices (`taxSettings.pricesIncludeTax`), company address | `blogname`, `woocommerce_email_from_address`, `woocommerce_currency`, `woocommerce_weight_unit`, `timezone_string`, `woocommerce_prices_include_tax`, store address settings (opt-in store settings) | Weight units GRAM → g, KILOGRAM → kg, OUNCE → oz, POUND → lbs; the street's two lines are address lines 1 and 2. |
| Store URL, platform and URL formats, store description, company name and phone, number and price formats, dimensions unit, order number prefix and suffix, languages, store taxes | Shop details (hashed with the shop) | |
| Legal pages (`legalPagesSettings`) that are enabled and shown inline | A policy page each; the terms page is WooCommerce's terms page, the privacy statement WordPress's privacy page | Legal pages that link to an external URL are kept with the shop details. |
| Categories (disabled ones too) | `product_cat` terms | Name, slug (custom, else generated), HTML description, full-size image with its alt text, SEO title and description, parent. Enabled, sort order, URL, origin and external id are meta. The old category URL 301s to the term. |
| Products | Products | Enabled → publish, disabled → private. HTML description, slug (custom, else generated), creation time, categories, SEO title and description. The old product URL (clean URLs are asked for) 301s to the product. Subtitle, every attribute, options with their price modifiers, wholesale prices, dimensions, volume, shipping settings, files, tax settings, purchase limits, pre-order setting, subscription settings, related products, composite components and the store's weight unit are meta. |
| Variations (`combinations`) | Simple product (no variations) or variable product with global attributes and variations | A variation is identified by its Ecwid id (stable across edits), the id an order item names (`combinationId`); a product without variations has a variant of its own. Options each variation selects → attributes. Price (a variation's own, else the product's); a compare-to (pre-sale) price above the price makes it the regular price and the price the sale price. SKU (an empty variation SKU is none; optional generated `ecwid-<id>`), UPC attribute as GTIN, cost (products without variations), variation image, weight in the store's unit (a variation without one takes the product's). |
| Stock | Stock quantity per variant | `unlimited` → unmanaged; else managed at `quantity`. `ALLOW_PREORDER` → backorders allowed. |
| Product images (`media.images`) | Media library, featured image + gallery | The main image first, full size (`imageOriginalUrl`), with its alt text; the main image alone when the product has no media list. |
| Brand attribute | WooCommerce brand | |
| Shipping and taxability | Virtual (needs no shipping), taxable | |
| Customers | WooCommerce customers (`ecwid-<customer id>`) | Email, the billing person's first and last name (whole as the first name when Ecwid doesn't split it), phone (else the default phone contact), private admin notes as the note, registration time, billing address, first saved shipping address. Language, customer group, tax id and exemption, marketing consent, contacts and every saved address are meta. Passwords are never available; customers reset theirs at cutover. |
| Discount coupons: percentage (optionally limited to products and categories), amount off the order, free shipping, or a percentage or amount with free shipping | One WooCommerce coupon each (`percent`, `fixed_cart`, free shipping) | Minimum order subtotal, single use (usage limit 1) or once per customer, launch and expiration time. Only an active coupon maps; a paused, expired or used-up one is retired. Name, type and limits are meta. |
| Orders | WooCommerce orders (HPOS-safe), Ecwid's totals | The order id (with the store's prefix and suffix) as the order number, in the store's currency. Status from payment and fulfillment: `AWAITING_PAYMENT` → pending; `INCOMPLETE` → failed; `CANCELLED` → cancelled; `REFUNDED` → refunded; `PAID` / `PARTIALLY_REFUNDED` → completed once `SHIPPED`, `DELIVERED` or `RETURNED`, cancelled when `WILL_NOT_DELIVER`, else processing; a custom payment status → on-hold. Paid date (the order's time) when paid; completed date when its last shipping label was bought. Payment method, transaction id, customer comment. |
| Order items | Line items | Linked to the product and its variation; subtotal (price without tax × quantity) and total after the item's coupon amount and promotions, without tax (gross prices: the discounted price less the tax charged on it); the options the customer chose as item meta; SKU. |
| Taxes (per item, on shipping, on surcharges) | Line taxes and tax lines with their rate (Ecwid's percent as a fraction) | An order whose tax comes only as a total gets one order-level tax line. |
| Shipping option | A shipping line (method name, method id, cost without tax, taxes on shipping) | |
| Handling fee, custom surcharges | Fees | |
| Discount coupon on the order | Coupon line; the order's discount (coupons and promotions) as the discount total | |
| Refunds | WooCommerce refunds (no money moves, nothing restocked) | Amount, reason, date. Ecwid's refunds have no ids and are only added, so each is identified by its place in the order's list. |
| Tracking number, purchased shipping labels, private admin notes | Private order notes, each once | |
| Statuses, internal id, promotions and coupon details, customer group and tax details, pickup time, checkout extra fields, invoices, referrers, UTM data, the shipping option's details and Ecwid's own subtotals and totals | Order meta | |
| Instant Site redirects | Redirects the plugin serves | A target on the store's own site (or a path) targets that path, which resolves to wherever its object was mirrored; another target keeps its URL. |
| Product reviews | WooCommerce product reviews | Rating, text, reviewer name and email, date. Published → approved; awaiting moderation → unapproved. A review tied to an order is a verified purchase. Customer link, order id and the reviewer's city and country are meta. |

## Gaps

| Ecwid | Why not mirrored | What it would need |
|---|---|---|
| Instant Site pages and their content | The pages API (`vuega.ecwid.com/api/v1/{storeId}/page`) returns only titles, paths, SEO fields and tile ids, never the content; not read | An export of the site's content |
| Blog | Ecwid has no blog API | — |
| Product options without variations (choices with price modifiers, text fields, dates, files) | WooCommerce has no product options; they are meta and the product is warned about | A product add-ons plugin |
| Option combinations not set up as variations | Ecwid prices them by option modifiers on the base product; WooCommerce sells only defined variations | Variations for every combination in Ecwid before the import |
| Wholesale price tiers, "pay what you want" prices, subscriptions | No WooCommerce schema; kept as meta (tiers are warned about) | Tiered-pricing / subscriptions plugins |
| Gift card products, gift card redemptions, store credit, loyalty | Unsupported (gift cards); redemptions and balances are order meta | A gift-card plugin |
| Variation cost prices | Ecwid documents a cost price only for products | — |
| Weights in carats | WooCommerce has no carat unit; the weight is meta and the product is warned about | — |
| Coupons taking an amount off some products, limited to new or repeat customers, to some shipping methods or to the store front page category | No WooCommerce coupon applies them exactly (reported as unsupported); Ecwid doesn't say whether a product amount is per item or in total | An advanced-coupon plugin |
| Promotions (automatic discounts) | WooCommerce applies discounts only through coupons; not read (orders keep what promotions took off) | A dynamic-pricing plugin |
| Customer groups, favorites, order statistics | No WooCommerce schema; the group is meta, statistics and favorites are volatile | A customer-groups plugin |
| A customer's language as a WordPress locale | Ecwid sends a bare language code (`fr`), not a locale (`fr_FR`); it is meta | A code → locale choice per store |
| Abandoned carts, subscriptions, shipping and payment methods, tax rules as configuration | Not history; each order keeps its titles and amounts | Tax class mapping |
| Category hierarchy | The term writer doesn't set term parents; the parent is mapped | Parent support in the term writer |
| Content in other store languages (`…Translated`) | One language (the store's default) per import | A translation plugin |
| Deleted-items history (`/<entity>/deleted`) | Not read; deletions are caught by the next full run | Reading it on incremental runs |
| Volatile statistics (favorites, review counts, product counts, coupon order counts, customer stats) | Would rewrite objects on every sale or visit | — |

## Unverified against a live store

Everything above is built from the official reference and its examples; no live store was read. Unverified:
that `updatedFrom` filters products by their last update (the products page describes it as the creation
date, an evident copy of `createdFrom`'s text) and whether its bound is inclusive; whether a product's update
time changes when only a variation changes; that `offset` and `limit` work on Instant Site redirects (the page
lists only `keyword` but its response is paged); that `cleanUrls=true` gives storefront paths that still serve
the old pages, and the shape of category URLs (`/products/<slug>` in the fixtures); that an order's `id` (with
its prefix) is stable (it is the identity; `internalId`, shown only in the example, is kept as meta); that an
item's `taxes[].taxOnDiscountedSubtotal` and `couponAmount` are for the whole item (all units), not per unit;
that a gross-price store's coupon amounts include tax; that `outOfStockVisibilityBehaviour` is a string (the
page types it boolean); that a customer's `billingPerson` and an order's persons carry `firstName` and
`lastName` (the customers page and the order example show them); that a variation without a `weight` weighs
what its product does; and whether Ecwid sends rate-limit headers (none are documented, so none are used).
