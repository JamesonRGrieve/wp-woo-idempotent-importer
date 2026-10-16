<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Lightspeed eCom (C-Series REST API) → WordPress + WooCommerce + WooCommerce Square

The single source of truth for what the Lightspeed eCom adapter mirrors. The adapter reads the REST API of
Lightspeed eCom C-Series (formerly SEOshop / WebshopApp; developers.lightspeedhq.com/ecom/). Lightspeed
publishes no OpenAPI document, so the responses the adapter reads are transcribed by hand from the official
endpoint reference into `tests/schemas/lightspeed.json` (each schema cites its page), and the fixtures are
validated against it. **Lightspeed Retail (R-Series), X-Series and the eCom E-Series (Ecwid-based) are other
products with other APIs and are out of scope.** **Mirrored** means the importer writes it into a native
WordPress / WooCommerce home; fields with no native home are kept on the object as `_mirror_<field>` meta.
**Gap** means WordPress + WooCommerce (+ WooCommerce Square) have no schema for it, or the adapter doesn't
read it. Gaps are decisions for the operator, tracked on the Deck card.

Access: an API key created in the eCom back office (Settings → Developers / API keys) with read permissions
for the shop, catalog (products, variants, categories, brands), customers, orders, invoices and shipments,
discounts, content (text pages, blogs) and reviews. The operator enters the key and its secret (sent as HTTP
Basic authentication), the shop's cluster (`eu1` → `https://api.webshopapp.com`, `us1` →
`https://api.shoplightspeed.com`) and the language code whose content is mirrored (one of bg, fc, da, nl,
en, us, fr, de, el, it, no, pl, pt, ru, es, sv, tr; requests go to `<cluster>/<language>/`). Webhooks
exist but the plugin's webhook endpoint is Shopify's; scheduled runs keep the mirror current.

Lightspeed limits each shop's API key to 300 requests per 5 minutes, 3,000 per hour and 12,000 per day, and
20 per second (developers.lightspeedhq.com/ecom/introduction/rate-limiting/). Requests are paced under the
hourly bucket (one every 1.2 seconds, also under 300 per 5 minutes). A store that needs more than 12,000
requests runs out the daily bucket: the 429 pauses the run, which carries on as the day's quota frees up.
The reference documents no rate-limit response headers and no cool-down after a 429, so pacing is by rate
alone and a 429 is retried with backoff
(README, "How it stays idempotent", for pauses and the credential hold). A first import costs about three
requests per product (variants, images, category links) and four per order (lines, shipments, invoices,
and the items of each credit note), plus one per page of each list.

Products, customers and orders are incremental (`updated_at_min`). A product is also re-read when one of its
variants changed, and an order when one of its shipments or invoices changed (credit notes are invoices).
The filter takes a time without a zone, so runs ask from a day before the last run. The other kinds are
read in full (unchanged objects are skipped by hash). Full runs retire the copies of anything Lightspeed no
longer has.

Money arrives as JSON numbers (prices to four decimals, e.g. `53.719`); they are read exactly (as the
decimal their JSON spelled), never through float arithmetic. Lightspeed keeps every price both with and
without tax: a retail shop shows prices with tax, a B2B shop (`isB2b`) without, and the mirror follows.

## Mirrored

| Lightspeed eCom | WordPress / WooCommerce home | Notes |
|---|---|---|
| Shop (`/shop.json`) and website (`/shop/website.json`): website name, email, currency, prices with tax (retail) or without (B2B), street, zip code, city, country | `blogname`, `woocommerce_email_from_address`, `woocommerce_currency`, `woocommerce_prices_include_tax`, store address settings (opt-in store settings) | The region comes by name only, so the store's state is left as it is. |
| Shop status, B2B / retail flags, domains, phone, fax, region, VAT and chamber-of-commerce numbers, industry, currency details; website title, description, keywords | Shop details (hashed with the shop) | |
| Categories | `product_cat` terms | Title, the slug its URL ends in, content (else the short description) as the description, image, parent. Long title, short description, visibility, depth, path, page type, sort order and sorting are meta. The old category URL (`/<category url>`, the whole tree path) 301s to the term. |
| Products | Products | Visible → publish, hidden → private. Content HTML, URL as slug, creation time. Long title, short description, template data fields, option set, supplier id and delivery-date id are meta. The old product URL (`/<url>.html`) 301s to the product. |
| Variants | Simple product (one variant without matrix options) or variable product with global attributes and variations | A variant is identified by its Lightspeed id (stable across edits), the id an order line links to. Matrix options → attributes (option name → chosen value); several variants without matrix options get one "Variant" attribute valued by each variant's title. Price with or without tax (see above); an old price above the price makes the old price the regular price and the price the sale price. Cost, SKU (optional generated `lightspeed-<variant id>`), EAN as GTIN, HS code, variant image, weight in its own unit (g, kg, oz, lb → lbs). Article code, unit price, the prices on the other side of tax, stock alert and buy limits, volume, dimensions, tax settings and withdrawal flag are meta. |
| Stock | Stock quantity per variant | Tracking `disabled` → unmanaged; `enabled` → managed, no backorders; `indicator` → managed, backorders allowed. Stock sold is volatile and not mirrored. |
| Product images | Media library, featured image + gallery (by sort order) | Full-size `src`; the product's main image when it has no image list. |
| Brand | WooCommerce brand | The brand's title (read once per brand). |
| Customers | WooCommerce customers (`lightspeed-<customer id>`) | Name, email, phone (else mobile), memo as the note, language locale, creation time, billing and shipping address (street, house number and extension on one line). Type, confirmation, middle name, gender, birth date, company and its numbers, national id and notification choices are meta. Passwords are never available; customers reset theirs at cutover. |
| Discount codes: a percentage off the order or off some products and categories, an amount off the order, optionally free shipping | One WooCommerce coupon each (`percent`, `fixed_cart`, free shipping) | Product and category limits, minimum order amount (checked before the discount), use limit (0 → unlimited), validity dates (whole days, the end date included, read as UTC). An inactive code is retired. Combination with discount rules, times used and tax/minimum settings are meta. |
| Orders | WooCommerce orders (HPOS-safe), Lightspeed's totals | Number as the order number, in the shop's currency (orders name none). Status: `processing_awaiting_payment` → pending; `on_hold` → on-hold; `processing_awaiting_shipment`, `processing_awaiting_pickup`, `processing_ready_for_pickup` → processing; `completed`, `completed_shipped`, `completed_picked_up` → completed; `cancelled` → cancelled. Paid date when paid or partially paid; completed date when a shipment shipped. |
| Order products | Line items | Linked to the product and variant; subtotal (base price × quantity) before and total after the line's discount, without tax; the variant title (other than "Default") as item meta. |
| Order taxes (per line, shipping and payment rates) | Line taxes and tax lines with their rate | An order whose tax comes only as a total gets one order-level tax line. |
| Shipping method | A shipping line (title, method id, cost, taxes) | |
| Payment costs | A fee | |
| Coupon code on the order | Coupon line; line discounts in the discount total | |
| Credit notes (invoices with `isCreditNote`) | WooCommerce refunds (no money moves, nothing restocked) | Amount with tax, credited product lines with their quantities and amounts. |
| Shipments | A private order note each, once | Number, state, tracking code. |
| Staff memo, customer comment | A private order note, the customer note | |
| Payment and shipment states and data, channel, custom status, discount details, delivery and shipping dates, company details, language | Order meta | |
| Text pages | WordPress pages | Content, URL as slug, creation time as publication time; hidden → draft. Sort order and lock are meta. The old URL (`/service/<url>`) 301s to the page. |
| Blogs | A post category each | Title, URL as slug; the old URL (`/blogs/<url>`) 301s to it. Comment setting and sort order are meta. |
| Blog articles | Posts in their blog's category | Title, content, summary as excerpt, author, publication time, image as the featured image; unpublished → draft. The old URL (`/blogs/<blog url>/<url>.html`) 301s to the post. Language is meta. |
| Redirects | Redirects the plugin serves | A target on the shop's own domain (or a path) targets that path, which resolves to wherever its object was mirrored; another target keeps its URL. Whether it was permanent is meta (the plugin always answers 301). |
| Product reviews | WooCommerce product reviews | Score, text, reviewer name, review date. Visible → approved; hidden → unapproved. The customer link and language are meta. |

## Gaps

| Lightspeed eCom | Why not mirrored | What it would need |
|---|---|---|
| Discount rules (automatic discounts with no code) | WooCommerce applies discounts only through coupons; not read | A dynamic-pricing plugin |
| Discount codes taking an amount off some products, discounting shipping costs, checking the minimum after the discount, or taken after tax (B2B) | No WooCommerce coupon applies them exactly (reported as unsupported); Lightspeed doesn't say whether a product amount is per item or in total | A shipping-discount or advanced-coupon plugin |
| Product tags, blog article tags | Kept in separate tag-link resources (two more requests per object); not read | Reading `tags/products` and `blogs/articles/tags` |
| Product attributes, filters and filter values, relations, metafields, quantity discounts, suppliers and delivery dates as objects | No WooCommerce schema; supplier and delivery-date ids are meta | Product add-ons / tiered-pricing plugins |
| Customer groups, customer metafields, logins | No WooCommerce schema | A customer-groups plugin |
| Order custom fields (`customFields` on a line), order events, order metafields, returns, tickets | No WooCommerce schema; not read | — |
| Shipping methods, payment methods, taxes and tax overrides as configuration | Store configuration, not history; each order keeps its titles and amounts | Tax class mapping |
| State and province on customers and the shop | Customers and the shop carry the region by name ("Nova Scotia"), kept as text; orders carry the code Lightspeed resolved | A name → code lookup on the WordPress side |
| Category hierarchy and visibility | The writer doesn't set term parents; parent and visibility are meta | Parent support in the term writer |
| Content in other shop languages | One language per import (requests are per language) | One WordPress site, or a translation plugin, per language |
| Multi-currency orders | Orders name no currency; every order is mirrored in the shop's currency | — |
| Blog comments | Not read | The comment writer for Lightspeed comments |
| Reviewer email, verified purchase | Lightspeed sends neither | — |
| Volatile statistics (stock sold, discount times used, customer last visit and IP) | Would rewrite objects on every sale or visit | — |

## Unverified against a live shop

Everything above is built from the official reference and its examples; no live shop was read. Unverified:
the storefront URL patterns used for 301s (`/<url>.html`, `/service/<url>`, `/blogs/<blog>/<article>.html`,
categories at their URL path); that `page`/`limit` work on `blogs/articles`, `orders/{id}/products` and the
`variants?product=` list (documented) the same way everywhere, and that an empty list is `[]` (not
`false`); the zone of `updated_at_min` (hence the one-day margin); whether `isB2b` is what decides prices
with or without tax; that a credit note's item quantities are negative and its item type is `product`; that a
review's `name` is the reviewer's name (the reference calls it the title); that `usageLimit` 0 means
unlimited; and whether the API sends rate-limit headers (none are documented, so none are used).
