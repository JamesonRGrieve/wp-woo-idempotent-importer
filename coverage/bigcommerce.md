<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: BigCommerce (REST Management API) → WordPress + WooCommerce + WooCommerce Square

The single source of truth for what the BigCommerce adapter mirrors. The adapter reads BigCommerce's REST
Management API (v3, and v2 where BigCommerce has no v3: the store, orders, legacy coupons and blog posts),
validated against BigCommerce's published OpenAPI 3.1 documents (pinned by hash in `tools/SpecCache.php`).
Every store-owned entity of those APIs is listed. **Mirrored** means the importer writes it into a native
WordPress / WooCommerce home; fields with no native home are kept on the object as `_mirror_<field>` meta.
**Gap** means WordPress + WooCommerce (+ WooCommerce Square) have no schema for it, or BigCommerce doesn't
expose it. Gaps are decisions for the operator, tracked on the Deck card.

Access: a store-level API account (Settings → API → Store-level API accounts) with read-only scopes for
Information & settings, Products, Customers, Orders, Order transactions, Marketing and Content. The
operator enters the store hash (the code after `/stores/` in the account's API path) and the account's
access token, sent as `X-Auth-Token`. BigCommerce has webhooks, but the plugin's webhook endpoint is
Shopify's; scheduled runs keep the mirror current. Products, customers and orders are incremental (by
modification time); categories, coupons, promotions, pages, the blog, posts, redirects and reviews have no
modification filter and are read in full (unchanged objects are skipped by hash). Full runs retire the
copies of anything BigCommerce no longer has. BigCommerce's quota depends on the plan and is reported on
every response (`X-Rate-Limit-Requests-Left`, `X-Rate-Limit-Time-Reset-Ms`): when the window is down to its
last request, the next one waits for the reset, so the quota is never run out; a 429 is still waited out
for the reset it reports (README, "How it stays idempotent", for pauses and the credential hold).

Money arrives as decimal strings or JSON numbers; both are read exactly (a number as the decimal its JSON
spelled, exponents expanded), never through float arithmetic.

## Mirrored

| BigCommerce | WordPress / WooCommerce home | Notes |
|---|---|---|
| Store name, order email (else admin email), currency, weight unit, timezone, prices entered with tax | `blogname`, `woocommerce_email_from_address`, `woocommerce_currency`, `woocommerce_weight_unit`, `timezone_string`, `woocommerce_prices_include_tax` (opt-in store settings) | Weight units: LBS/pounds → lbs, ounces → oz, KGS → kg, grams → g. |
| Store domain, URLs, display address, phone, country, language, currency format, dimension unit, plan, logo, channel and site ids, features | Shop details (hashed with the shop) | The store address is one free-text display address, which WooCommerce's address fields can't take exactly, so the address settings are left as they are. |
| Categories (every category tree) | `product_cat` terms | Name, the slug the storefront URL ends in, description, image, SEO title and description. Parent, tree, visibility, sort order, keywords, layout and default product sort are meta. The old category URL 301s to the term. |
| Products (physical, digital) | Products | Visible → publish, hidden → private. Description HTML, slug from the storefront URL, SEO page title and meta description, creation time. Digital products are virtual. The old product URL 301s to the product. |
| Variants | Simple product (one variant with no option values: the base variant) or variable product with global attributes and variations | A variant is identified by its BigCommerce id (stable across edits), the id an order line names. Option display name → value label. Price, sale price, cost and weight fall back to the product's when the variant leaves them unset; a zero sale price or cost means none. SKU (the product's for a base variant; optional generated `bigcommerce-<variant id>`), GTIN (else UPC), variant image. |
| Inventory | Stock quantity, managed when tracked | `variant` tracking: each variation's level; `product` tracking on a simple product: the product's level; `none`: always in stock. |
| Product images | Media library, featured image (the thumbnail) + gallery (by sort order) | Largest size (`url_zoom`); description as alt text. |
| Brand | WooCommerce brand | |
| Custom fields, condition, availability (incl. pre-order release date and message), hidden price, retail and MAP price, tax class and tax code, warranty, keywords, featured flag, free / fixed shipping, dimensions, order quantity limits, gift wrapping, related products, bulk pricing rules, bin picking number, layout, sort order, Open Graph, videos, option set, channels; each variant's MPN, UPC, retail price, dimensions, shipping, purchasing-disabled flag and message, warning level | Product meta | No native WooCommerce field. |
| Customers | WooCommerce customers (`bigcommerce-<customer id>`) | Name, email, phone, notes, creation time; the first saved address as billing and shipping (with the company; the customer's phone when the address has none). Every saved address, customer group, tax-exempt category, store credit, attributes, form fields, channels and marketing consent are meta. Passwords are never available; customers reset theirs at cutover. |
| Legacy coupons (v2): amount per item, percentage, amount off the order, free shipping | One WooCommerce coupon each (`fixed_product`, `percent`, `fixed_cart`, free shipping) | Limited to products or categories (per item and percentage); minimum purchase, expiry, total uses, once per customer. A disabled coupon is retired. |
| Coupon promotions (v3) whose one rule takes a percentage or amount off the order | One WooCommerce coupon per code (`percent`, `fixed_cart`) | Minimum spend, start and end, total uses (one code), once per customer. A disabled or invalid promotion is retired. Combination settings, channels and notifications are meta. |
| Orders | WooCommerce orders (HPOS-safe), BigCommerce's totals | Number shown as `#<order id>`. Status: Pending → pending; Awaiting Payment, Manual Verification Required, Disputed → on-hold; Awaiting Fulfillment, Awaiting Shipment, Awaiting Pickup, Partially Shipped, Partially Refunded (before shipping) → processing; Shipped, Completed, Partially Refunded (after shipping) → completed; Refunded → refunded; Cancelled → cancelled; Declined → failed. Times converted from RFC 2822 to UTC. |
| Order products | Line items | Linked to the product and variant; subtotal before and total after the line's discounts; option choices, configurable fields, gift wrapping and event as item meta; gift certificates flagged. |
| Order taxes (per line) | Line taxes and tax lines with their rate | Read with `details`; an order whose tax comes only as a total gets one order-level tax line. |
| Shipping addresses | Shipping lines (method, cost, taxes); the first one is the shipping address | Each address's handling cost is a "Handling" fee. |
| Gift wrapping cost, order fees | Fees | |
| Coupon codes on the order | Coupon lines; coupon and manual discounts in the discount total | |
| Refunds (v3) | WooCommerce refunds (no money moves, nothing restocked) | Refunded product lines with their quantities. |
| Shipments | A private order note each, once | Carrier, tracking number and link. |
| Staff notes, customer message | A private order note, the customer note | |
| Gift certificates | Square Gift Cards (the store's one spendable balance), kept in the importer's gift card ledger and shown in the customer's My Account | Opt-in kind `gift_card`, with the Square account set up (README, "Gift cards and store credit"). The code is the Square card number (separators dropped) where Square can take it, else a new number shown in the customer's account. Active certificates are loaded; pending (unpaid) and disabled ones carry no balance. BigCommerce keeps no redemption history: the ledger is the issue and what was spent before the move. Linked to the recipient's Square customer (by email). Sender, message, template and dates are kept with the card. |
| Payment status, payment method, provider id, channel, order source, external source/ids, currency exchange rate, store credit and gift certificate amounts, refunded amount, IP and geo-IP country | Paid date (captured or paid), payment method title, order meta | |
| Content pages (page, raw) | WordPress pages | Body, slug from the URL, SEO title and description. Hidden → draft, customers only → private. Parent, sort order, home-page flag, channel and keywords are meta. BigCommerce keeps no publication time, so a page is dated when first mirrored. The old URL 301s to the page. |
| Blog | A post category | Named and slugged after the blog page (else "Blog" at `/blog/`); the blog page's old URL 301s to it. |
| Blog posts | Posts in the blog's category | Title, body, summary as excerpt, tags, author, publication time, thumbnail as the featured image, meta description; unpublished → draft. The old post URL 301s to the post. |
| Storefront 301 redirects | Redirects the plugin serves | To a URL as given; to a product, category, page or post through that object's storefront path, which resolves to the mirrored object. |
| Product reviews | WooCommerce product reviews | Rating, title and text, name and email, review date. Approved → approved; pending and disapproved → unapproved. |

## Gaps

| BigCommerce | Why not mirrored | What it would need |
|---|---|---|
| Legacy shipping discounts, and coupons limited to countries or shipping methods, or usable more than once per customer | WooCommerce coupons can't express them (reported as unsupported) | A shipping-discount or advanced-coupon plugin |
| Legacy amount-off-the-order and free-shipping coupons limited to products or categories | BigCommerce gives the order discount when those products are bought; a WooCommerce cart coupon with product restrictions discounts only those lines (reported as unsupported) | — |
| Automatic promotions; promotions with item, gift, fixed-price-set or shipping rules, several rules, a capped discount, customer-group, segment, order-count, schedule or address conditions; codes with their own schedule or limits | WooCommerce applies discounts only through coupons, which can't express these (reported as unsupported) | A dynamic-pricing plugin |
| Promotion combination rules (`can_be_used_with_other_promotions`) | The coupon writer doesn't set individual use; kept as meta | Individual-use support in the discount writer |
| Product modifiers and option sets beyond variants, complex rules, bulk pricing | No WooCommerce schema; options and bulk rules are meta | Product add-ons and tiered-pricing plugins |
| Product-level stock on a product with variants | The variations are mirrored untracked; the product's stock is meta (a warning is logged) | Parent-level stock in the product writer |
| Variants BigCommerce won't sell (purchasing disabled), pre-order and price-hidden products | WooCommerce sells them; the flags are meta (purchasing disabled is warned) | A catalog-mode or pre-order plugin |
| Weight in tonnes | WooCommerce has no tonne unit; the weight is meta (a warning is logged) | — |
| Tax classes | No mapping from BigCommerce tax classes to WooCommerce tax classes; every product is taxable, its tax class id is meta | Tax class mapping |
| State and province | BigCommerce sends the name ("Alberta"); WooCommerce's state field keeps it as text, not its state code | A name → code lookup on the WordPress side |
| Store address | One free-text display address; WooCommerce's address settings are left as they are (the text is kept with the shop) | — |
| Category hierarchy and visibility | The writer doesn't set term parents; parent and visibility are meta | Parent support in the term writer |
| Order fee taxes | BigCommerce doesn't say which tax a fee's tax is; the fee has no tax line (the order's tax total includes it) | — |
| Payment transactions (gateway transaction ids) | Separate transactions endpoint, not read | One extra request per order |
| Incomplete orders (abandoned checkouts) and archived orders | Not store history | — |
| Contact-form and link pages | A form and a menu link, not content pages (reported as unsupported) | A forms plugin; menus |
| Brands as pages, brand images | WooCommerce brands are terms; only the name is mirrored | Brand term details in the product writer |
| Volatile statistics (views, units sold, review counts, calculated price) | Would rewrite every product on every sale or view | — |
| Product videos, metafields, channels, price lists, customer groups, wishlists, abandoned carts, store credit balances (kept as customer meta; into Square store credit is an operator decision), subscribers, widgets, themes, scripts | No WooCommerce schema, or not store content (videos and channels are product meta) | Dedicated WooCommerce extensions |
| Multi-storefront content | Pages, posts and redirects of every channel are mirrored into one site; the blog is the first blog page's | A multisite per storefront |
| BigCommerce webhooks | The plugin's webhook endpoint verifies Shopify's signatures only | A BigCommerce webhook receiver |

Published-spec discrepancies (the fixtures follow the spec; the mapper reads both forms):

| Spec says | Real API (per BigCommerce's docs and examples) | Effect |
|---|---|---|
| Product list items have no `variants` property | `include=variants` returns them | The adapter reads each product's variants from `/catalog/products/{id}/variants` (one extra request per product). |
| Product prices, weights and dimensions are strings | JSON numbers | Both are read exactly. |
| A `page`-type content page has no `body` (only the `raw` page schema does) | `include=body` returns it with the page's SEO fields | Fixture pages with a body omit `meta_title`/`meta_description`, so they validate; SEO mapping is unit-tested on synthetic pages. |
