<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Magento / Adobe Commerce (REST API) → WordPress + WooCommerce + WooCommerce Square

The single source of truth for what the Magento adapter mirrors. The adapter reads one store view of a
Magento Open Source / Adobe Commerce 2.4 store through its REST API (`<store URL>/rest/<store view code>/V1`),
validated against Adobe's published admin REST schema for 2.4.9 (Swagger 2.0, "All inclusive", pinned to a
commit of AdobeDocs/commerce-webapi in `tools/SpecCache.php`). Every store-owned entity of that API the
adapter reads, and every one it doesn't, is listed. **Mirrored** means the importer writes it into a native
WordPress / WooCommerce home; fields with no native home are kept on the object as `_mirror_<field>` meta.
**Gap** means WordPress + WooCommerce (+ WooCommerce Square) have no schema for it, or Magento doesn't expose
it. Gaps are decisions for the operator, tracked on the Deck card.

Access: an integration (System → Extensions → Integrations) with read access to Stores (settings and
currency), Catalog (products, categories, attributes), Customers (and customer groups), Sales (orders, credit
memos, shipments), Marketing (cart price rules) and Content (pages). Its access token is sent as
`Authorization: Bearer <token>`; since 2.4.4 a store accepts an integration token on its own only when
Stores → Configuration → Services → OAuth → Consumer Settings → "Allow OAuth Access Tokens to be used as
standalone Bearer tokens" is Yes (`oauth/consumer/enable_integration_as_bearer`; Adobe, "Token-based
authentication": https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-token).
The operator enters the store URL, the store view code (blank for `default`) and the token. A rejected token
stops the run and holds scheduled runs until the operator saves the settings (README, "How it stays
idempotent"), so a revoked or mistyped token is never retried into a lockout. Magento Open Source sends no
webhooks; scheduled runs keep the mirror current. Customers and orders are incremental (by `updated_at`).
Products are read in full on every run: Magento doesn't move a product's `updated_at` when its stock changes
or when a configurable product's child changes, so a modification filter would miss both. Categories, cart
price rules and CMS pages have no modification filter and are read in full (unchanged objects are skipped by
hash). Full runs retire the copies of anything Magento no longer has.

Rate limits: Magento Open Source and Adobe Commerce document no request-rate limit for these REST endpoints
(2.4.7's optional rate limiting, off by default, covers only the cart payment-information and order
endpoints: https://developer.adobe.com/commerce/webapi/get-started/rate-limiting), so requests aren't paced.
A store behind Fastly (Adobe Commerce on cloud) may answer 429 when its optional rate limiting is configured
(https://experienceleague.adobe.com/en/docs/experience-cloud-kcs/kbarticles/ka-27428); a 429 is waited out
for its Retry-After, else backed off, and a long wait pauses the run to resume later. Searches ask for 100
items a page, under the default "Maximum Page Size" of 300
(https://developer.adobe.com/commerce/webapi/get-started/api-security). A search past its last page answers
with the last page again, so a list ends when the items read reach its `total_count`, never at an empty page.

Money arrives as JSON numbers; they are read exactly (the decimal their JSON spelled), never through float
arithmetic. Order amounts are the order currency's; the base-currency twins are meta. Timestamps are
`Y-m-d H:i:s` in UTC; a cart price rule's dates are calendar days in the store's timezone.

## Mirrored

| Magento | WordPress / WooCommerce home | Notes |
|---|---|---|
| Store name (the store group's name, as the admin shows it), base currency, weight unit, timezone | `blogname`, `woocommerce_currency`, `woocommerce_weight_unit`, `timezone_string` (opt-in store settings) | Weight units: kgs → kg, lbs → lbs. |
| Store view, website, locale, base/link/media URLs, display currency, allowed currencies and exchange rates | Shop details (hashed with the shop) | |
| Categories below the store roots (levels 2+, every tree) | `product_cat` terms | Name, url key as the slug, description, image, meta title and description. Parent, path, level, position, active and in-menu flags and the other attributes (display mode, anchor, layout, sort options) are meta. The old URL (url path + `.html`) 301s to the term. |
| Products: simple, virtual, downloadable | Simple products (virtual and downloadable are virtual) | Enabled → publish, disabled → private. Visibility catalog / search / both → WooCommerce catalog visibility. Description, url key as the slug, meta title and description, creation time. The old URL (url key + `.html`) 301s to the product. |
| Configurable products and their child products | Variable products with global attributes and one variation per enabled child product on the store's website | A variation is identified by its child product's id, the id an order's child line names. The configurable attributes' labels (e.g. Colour) and the option labels of each child's values are the attribute values. Each child's SKU, price, special price, cost, weight, stock and first image. |
| Price, special price, cost | Regular price, sale price (when below the price), cost of goods | The store's base prices. |
| Stock items | Stock quantity, managed, backorders | A stock item that uses the configuration is taken as Magento's defaults (stock managed, no backorders). |
| Gallery images | Media library, featured image (the base image) + gallery (by position) | Enabled images only, as `<media URL>catalog/product<file>`; label as alt text. |
| Category assignments | Product categories | The store root categories are left out. |
| Country of manufacture | The variation's origin country | |
| Other product attributes (custom attributes), custom options, tier prices, product links, attribute set, website and category links, stock item, downloadable links and samples, each child's details | Product meta | No native WooCommerce field. Custom options, tier prices, dated special prices and downloadable links are warned. |
| Customers | WooCommerce customers (`magento-<customer id>`) | Name, email (lower-cased), creation time, the default billing and shipping addresses (street lines, city, region code, postcode, country, telephone, company), the billing phone as the customer's phone. Every saved address, group, website, store view, date of birth, gender, tax/VAT number, newsletter subscription and other attributes are meta. Passwords are never available; customers reset theirs at cutover. |
| Cart price rules with coupons: percentage of each item, fixed amount off each item, fixed amount off the cart | One WooCommerce coupon per code (`percent`, `fixed_product`, `fixed_cart`) | For every item and every customer group; a minimum subtotal (`base_subtotal >=`); free shipping for the whole cart; from and to dates in the store's timezone (start meta, expiry at the end of the to date); uses per coupon; once per customer. An inactive rule, or one not on the store's website, is retired. Labels, description, priority, stop-further-rules flag and usage counts are meta. |
| Orders (of the store's website's store views) | WooCommerce orders (HPOS-safe), Magento's totals | Number shown as `#<increment id>`. State: new, pending payment → pending; payment review, on hold → on-hold; processing → processing; complete → completed; closed → refunded; canceled → cancelled. Paid when Magento has taken payment (`total_paid`); completed at the last shipment. |
| Order items | Line items | A configurable product's parent and child lines are one line, linked to the product and the child's variation; other lines link to their product. Row total before and after the line's discount; taxes from Magento's applied taxes. |
| Applied taxes | Line and shipping taxes, tax lines with their rate | An order whose tax comes only as a total gets one order-level tax line. |
| Shipping method | A shipping line (description, method code, amount, taxes); the shipping address | |
| Coupon codes | Coupon lines; the order's discount as the discount total | |
| Credit memos | WooCommerce refunds (no money moves, nothing restocked) | Total, first comment as the reason, refunded line quantities. |
| Shipments | A private order note each, once | Carriers and tracking numbers. |
| Order comments (status history) | A private order note each, once | |
| Customer note, payment method title, last transaction id | The customer note, payment method title, transaction id | |
| Store, customer group, base-currency totals, paid/refunded/due totals, applied rules, discount description, weight, remote IP, payment method and card type | Order meta | |
| CMS pages | WordPress pages | Title, content, identifier's last segment as the slug, creation time, meta title and description. Inactive → draft. Content heading, layout, meta keywords, sort order, theme and layout settings are meta. The old URL (`/<identifier>`) 301s to the page; the home page is the store root. |

## Gaps

| Magento | Why not mirrored | What it would need |
|---|---|---|
| Bundle, grouped and gift card products | No exact WooCommerce equivalent (reported as unsupported); bundle and gift card order lines carry no product link | Product-bundles / grouped-product support in the product writer |
| Simple products "not visible individually" that belong to no configurable product (e.g. a grouped product's members) | Read only as configurable children; an order line for one waits for a product that is never mirrored | — |
| Custom options, tier and customer-group prices, special price dates, MSRP | One WooCommerce price per product; kept as meta (warned) | Product add-ons and tiered-pricing plugins |
| Downloadable files and samples | WooCommerce downloads are file uploads the REST API doesn't hand out; the product is virtual and its links are meta (warned) | Reading the files from the store's media |
| Multi-source inventory (sources, salable quantity) | The legacy stock item is read; source items aren't | Reading `/V1/inventory/source-items` |
| Configured stock defaults (manage stock, backorders) | The store configuration isn't in the REST API; Magento's defaults are assumed where a stock item uses the configuration | — |
| Store contact email, address, prices-include-tax setting, URL suffix | Not exposed by the REST API; the WooCommerce settings are left as they are, and `.html` (Magento's default) is assumed for old product and category URLs | — |
| URL rewrites (custom redirects, product URLs nested under categories) | No URL rewrite endpoint in the REST API | A storefront crawl or a database export |
| Category hierarchy and visibility | The term writer doesn't set term parents; parent, active and in-menu flags are meta | Parent support in the term writer |
| Cart price rules without a coupon, buy X get Y, item (action) conditions, cart conditions other than a minimum subtotal, discount caps and steps, shipping discounts, free shipping for matching items, customer-group limits, coupons with their own limits or expiry, more than one use per customer | WooCommerce coupons can't express them (reported as unsupported) | A dynamic-pricing or advanced-coupon plugin |
| Catalog price rules | Not read; they change prices without coupons | A dynamic-pricing plugin |
| Tax classes and rates | Every product is taxable unless its tax class is "None"; the tax class id is meta | Tax class mapping |
| Prices entered with tax | Orders are written with prices excluding tax (Magento's default); their totals are Magento's either way | The store's tax configuration |
| Invoices | Not read; the paid date is the order's creation once Magento has taken payment | One extra search per order page |
| Order line options (configurable, custom options) | Magento sends option ids, not labels; the child's variation names the choice | Resolving option labels |
| Orders of other websites | Only the store's website's store views are read | One run per website |
| Product reviews and ratings | No reviews endpoint in the Magento REST API | A database export |
| CMS blocks and widgets, Page Builder content, content directives (`{{media}}`, `{{widget}}`) | Blocks aren't pages; directives are kept as written in page content | Rendering the directives |
| Blog | Magento has no blog (extensions add their own) | — |
| Customer passwords, newsletter queue, wishlists, carts, reward points, store credit, gift card accounts, RMA, B2B companies and shared catalogs | Not store content WooCommerce can hold, or Adobe Commerce-only features | Matching WooCommerce extensions |
| API nullability | Adobe's schema marks some fields required strings that a store sends as null (a payment's `account_status` and `cc_last4`, a stock item's `low_stock_date`, a rule condition's `operator`); the fixtures send strings so they validate, and the reader treats empty and null alike | — |
| OAuth 1.0a request signing | Integrations are read with their access token as a bearer token, which needs the store setting above | Signing requests with the consumer key and secret |
