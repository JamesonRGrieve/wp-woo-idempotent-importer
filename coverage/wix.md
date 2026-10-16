<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Wix (REST APIs) → WordPress + WooCommerce + WooCommerce Square

The single source of truth for what the Wix adapter mirrors. The adapter reads Wix's REST APIs (Site
Properties, Stores catalog V3 or V1, Contacts, Coupons, eCommerce Orders with their transactions and
fulfillments, Blog, SEO Redirects and Reviews). Wix publishes no OpenAPI document, so the response shapes
the adapter reads were transcribed by hand from Wix's official REST reference into
`tests/schemas/wix.json` (each schema cites its reference page) and every recorded fixture is validated
against it. **Mirrored** means the importer writes it into a native WordPress / WooCommerce home; fields
with no native home are kept on the object as `_mirror_<field>` meta. **Gap** means WordPress +
WooCommerce (+ WooCommerce Square) have no schema for it, or Wix doesn't expose it. Gaps are decisions for
the operator, tracked on the Deck card.

Access: an account owner generates an API key in the API Keys Manager with read permissions for Site
Properties, Wix Stores (catalog read, including the admin scope for hidden products and cost), Contacts,
Coupons, eCommerce orders and transactions, Blog, SEO redirects and Reviews, and enters it with the site
id; requests send the key in `Authorization` and the site in `wix-site-id`. The key is long-lived (no
token to renew). Wix's webhooks aren't used; scheduled runs keep the mirror current. Contacts and orders
are incremental (by update time), as are a V3 catalog's categories and products; an incremental product
run also re-reads the products whose inventory items changed (an inventory change doesn't update its
product). Coupons, the blog, posts, redirects, reviews and the whole V1 catalog have no update filter and
are read in full (unchanged objects are skipped by hash). Full runs retire the copies of anything Wix no
longer has.

Rate limits: Wix documents no request rate and sends no rate-limit headers; its troubleshooting page
(https://dev.wix.com/docs/api-reference/articles/work-with-wix-apis/troubleshooting/troubleshooting.md)
says to wait a minute after a 429. So requests aren't paced, and a 429 pauses the run for that minute
(it resumes where it was).

Money arrives as decimal strings (V3 catalog, orders, transactions) or JSON numbers (V1 catalog, coupons);
both are read exactly, never through float arithmetic. Times are ISO 8601, except a coupon's, which are
milliseconds since the epoch.

## Mirrored

| Wix | WordPress / WooCommerce home | Notes |
|---|---|---|
| Site properties: business name (else display name), email, payment currency, time zone, business address | `blogname`, `woocommerce_email_from_address`, `woocommerce_currency`, `timezone_string`, store address (opt-in store settings) | The address's street number and street name form the first line; an ISO 3166-2 state (`CA-SK`) becomes `SK`. |
| Display name, description, phone, fax, logo, locale, language, site categories, business schedule, languages, cookie policy, business type, company id | Shop details (hashed with the shop) | |
| Stores catalog version | — | Asked once per run; a V3 site is read from the V3 APIs, a V1 site from the V1 APIs, a site without Stores has no products. |
| V3 categories (the Stores tree, hidden ones included) | `product_cat` terms | Name, slug, plain-text description (line breaks kept), image, SEO title and description. Parent, visibility, breadcrumbs and tree are meta. |
| V1 collections | `product_cat` terms | Name, slug, description, main image. "All Products" is a real Wix collection and is mirrored as one. Visibility is meta. |
| Products (V3 and V1; physical and digital) | Products | Visible → publish, hidden → private. HTML description, slug, SEO title and description (from the SEO tags), creation time, brand → WooCommerce brand. Digital products are virtual. The old product page (`/product-page/<slug>`) 301s to the product. |
| Variants | Simple product (no options: its one variant) or variable product with global attributes and variations | A variation is identified by its product id and variant id (Wix variant ids repeat across products), the pair an order line names. Option name → choice name. A compare-at (V3) or undiscounted (V1) price above the selling price is the regular price, the selling price the sale price. SKU (optional generated `wix-<product>-<variant>`), barcode → GTIN (V3), cost (merchant data), variant image (V3). |
| Inventory | Stock quantity, managed when Wix tracks quantities | V3: the variant's inventory items (summed over locations); tracked by status only → unmanaged. Pre-order enabled → backorders allowed (V3). V1: each variant's stock. |
| Product media | Media library, featured image + gallery | Images in Wix's order (the first is the main media), alt text. Videos aren't images. |
| Weight | Product weight in the product's unit | V3: KG → kg, LB → lbs. |
| Handle, ribbons, info sections, options and modifiers, tax group, main category, physical and digital properties, subscriptions, purchase eligibility, currency (V3); SKU, weight, ribbon, additional info sections, custom text fields, discount, dimensions, price per unit, numeric id (V1); each variant's visibility, dimensions and pre-order settings | Product meta | No native WooCommerce field. Derived and volatile values (price and cost ranges, availability, item counts, revision, pre-order counters) aren't mirrored, so a sale doesn't rewrite a product. |
| Contacts | WooCommerce customers (`wix-<contact id>`) | Name, email, phone, locale, creation time. The address tagged billing (else the default, else the first) is the billing address and the one tagged shipping (else default, else first) the shipping address, each with its recipient (else the contact's name, company and phone). Every address, company, additional emails and phones, birthday, time zone, segments, source, email and SMS consent and member details (not logins) are meta. Passwords are never available. |
| Coupons: percentage off (the store, a product or a collection), amount off the order, free shipping | One WooCommerce coupon each (`percent`, `fixed_cart`, free shipping) | Minimum subtotal, start and expiry, total uses, once per customer. A deactivated coupon is retired. Type, tags, subscription settings and display data are meta. |
| Orders | WooCommerce orders (HPOS-safe), Wix's totals | Number shown as `#<number>`. Status: canceled → cancelled; rejected → failed; approved and fully refunded → refunded; payment declined → failed; paid (or partly refunded) and fulfilled → completed; paid → processing; payment outstanding (offline, pending, partial) → on-hold. Archived orders are mirrored (archiving only hides them in Wix). |
| Order line items | Line items | Linked to the Wix Stores product and variant; subtotal before and total after the line's discounts; taxes by jurisdiction; description lines (chosen options) as item meta; gift cards flagged. |
| Order taxes | Line, shipping and fee taxes with their rate; tax lines from the order's breakdown | An order taxed with no breakdown gets one order-level tax line. |
| Shipping option | A shipping line (title, code, price before discounts, taxes) and the shipping address | Shipping discounts are in the discount total. |
| Additional fees | Fees with their taxes | |
| Coupon codes | Coupon lines; all discounts in the discount total | |
| Payments | Paid date (the first payment that took the money), payment method title, transaction id; every payment as meta | Card brand and last digits, gift card and membership payments are meta. |
| Refunds | WooCommerce refunds (no money moves, nothing restocked) | Only refunds that returned money (a failed or pending refund isn't one); refunded lines with their quantities and refunded amounts; the reason. |
| Fulfillments | A private order note each, once | Carrier, tracking number and link. The last one dates completion. |
| Buyer note | Customer note | |
| Blog | A post category "Blog" at `/blog` | Posts in no category belong to it; `/blog` 301s to it. |
| Blog categories | Post categories | Label, slug, description, cover image; the old category page 301s to it. Menu position, language and translation group are meta. |
| Blog posts (published) | Posts in their first category (else the blog) | Title, slug, rich content as HTML, excerpt, hashtags as tags, first publication time, cover image as the featured image, SEO title and description. The old post URL (`/post/<slug>`) 301s to it. Every category, tag ids, featured and pinned flags, writer, reading time, language and related posts are meta. |
| URL redirects (including those Wix made when a URL changed) | Redirects the plugin serves | To a full URL as given; to a site path through that path, which resolves to the mirrored object. |
| Product reviews (Wix Stores, published and in moderation) | WooCommerce product reviews | Rating, title and body, author name, review date, verified flag, the store's reply. Approved (or published without moderation) → approved; in moderation or rejected → unapproved. |

## Gaps

| Wix | Why not mirrored | What it would need |
|---|---|---|
| Site pages, menus, the site's design and CMS (Data) collections | Wix has no REST API for page content and menus; CMS collections are free-form | Bring pages over by hand; a CMS-collection mapping per site |
| Fixed-price and buy-X-get-Y coupons, an amount off some products, coupons limited to one item or usable more than once per customer, coupons of other Wix apps (Bookings, Events, Pricing Plans) | WooCommerce coupons can't apply them the same way (reported as unsupported) | A dynamic-pricing plugin |
| Automatic discounts (discount rules) | Not coupons; they appear on orders (in the discount total and meta) but aren't mirrored as rules | A dynamic-pricing plugin |
| Product modifiers (customer text, choices that don't make variants) and V1 custom text fields | No WooCommerce schema; they are meta (modifiers are warned) | A product add-ons plugin |
| Hidden variants; variants out of stock without a tracked quantity | WooCommerce sells them / keeps them in stock (both warned; visibility is meta) | — |
| Pre-order limits and messages | Pre-order maps to backorders; its limit and message are meta | A pre-order plugin |
| Multi-location stock | Quantities are summed into one stock level; locations are meta | Multi-location inventory |
| V1 catalog weight | The V1 API doesn't say the store's weight unit; weight is meta (warned) | The site's unit setting |
| V1 inventory pre-order settings, V1 choice images | Not read | — |
| Store category pages | Wix's API gives no category page URL, so old category URLs aren't redirected | — |
| Category hierarchy | The term writer doesn't set parents; the parent is meta | Parent support in the term writer |
| Blog posts in several categories | A post is filed in its first category; the others are meta | Several categories in the article writer |
| Blog drafts, scheduled posts, comments, post authors as users | Query Posts returns published posts; the writer's member id is meta | Draft posts API; comments; member → user mapping |
| Rich content blocks other than paragraphs, headings, lists, quotes, code, dividers and images (galleries, videos, embeds, buttons, tables, polls, app embeds…), and in-post anchor links | No exact HTML (the post is reported as unsupported); text colour, size and font are presentation and aren't carried | A Ricos → block renderer |
| Group redirects (every path under a prefix) | The plugin redirects exact paths (reported as unsupported) | Prefix redirects |
| Review authors' emails | Wix gives the author's contact id (meta), not an email | — |
| Pending, rejected and initialized orders | Search Orders doesn't return them (abandoned or unfinished checkouts) | — |
| Contacts that aren't customers | Every contact is mirrored as a customer (Wix keeps one contact list for the whole site) | — |
| Tax groups | No mapping from Wix tax groups to WooCommerce tax classes; every product is taxable, its tax group is meta | Tax class mapping |
| Subscriptions, gift card balances, loyalty, abandoned carts, wishlists | No WooCommerce schema | Dedicated WooCommerce extensions |

## Unverified against a live site

The adapter is proven against fixtures that follow Wix's reference pages, not against a live site. Not
yet confirmed on real Wix responses:

- The blog posts path: the reference's schema block says `/v3/posts/query`; its examples (and the blog
  categories method) use `/blog/v3/posts/query`, which the adapter uses.
- `catalogReference.options.variantId` on V3 order lines, and that a product without options has one
  variant in both catalogs.
- The coupon scope values (`stores`, group `product` / `collection`) and whether a collection scope names a
  V3 category id on a V3 site.
- That Query Coupons returns expired coupons without a filter.
- The `updatedDate` `$gt` filter format (ISO 8601 with milliseconds, UTC).
- That a Ricos image given only a Wix Media id is served from `https://static.wixstatic.com/media/<id>`.
- The end-to-end suite covers a V3 site only; the V1 catalog is covered by the unit tests.
