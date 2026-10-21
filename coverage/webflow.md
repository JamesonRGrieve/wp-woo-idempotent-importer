<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Webflow (Data API v2) → WordPress + WooCommerce

The single source of truth for what the Webflow adapter mirrors. The adapter reads Webflow's Data API v2
(developers.webflow.com/data/reference). Webflow publishes its OpenAPI 3.1 document (webflow/openapi-spec); the
fixtures are validated against it, pinned to the commit of 2026-09-02. **Mirrored** means the importer writes it
into a native WordPress / WooCommerce home; fields with no native home are kept on the object as `_mirror_<field>`
meta. **Planned** is work the importer doesn't do yet; each is an operator decision on the Deck card.

## Access

Either a site API token (Site settings → Apps & integrations → API access, read access to the scopes below), or a
Webflow OAuth app connected with "Connect to Webflow": authorization at `https://webflow.com/oauth/authorize`, the
code traded at `https://api.webflow.com/oauth/access_token` (the OAuth reference page; the OpenAPI document names
`/oauth/token` instead). Scopes, each endpoint's "Required scope": `sites:read`, `cms:read`, `pages:read`,
`assets:read`, `ecommerce:read`. Webflow's access tokens don't come with a refresh token, so a token Webflow rejects
stops the run and holds scheduled runs until the site is connected again. The operator also enters the site ID
and, optionally, the ID of the CMS collection that is the blog. Webflow's webhooks aren't used; scheduled runs keep
the mirror current.

Webflow allows 60 requests a minute per token on Starter and Basic sites and 120 on CMS, e-commerce and Business
sites, reports what is left of the minute in `X-RateLimit-Remaining`, and answers a 429 with a `Retry-After`,
typically 60 seconds (developers.webflow.com/data/reference/rate-limits). Requests are paced at 60 a minute;
when the minute is down to its last request the run pauses until the window passes. Lists are paged 100 at a
time (`offset`, `limit`, `pagination.total`).

Nothing is incremental: Webflow's product, order, page and redirect lists have no changed-since filter, so every
run reads in full and unchanged objects are skipped by hash. A product costs one extra request per SKU (its
inventory), and a page one per 100 DOM nodes plus one per image asset.

Money arrives in the currency's minor unit (`2499` is 24.99); it is converted exactly, never through floats.

## Mirrored

| Webflow | WordPress / WooCommerce home | Notes |
|---|---|---|
| Site: name, timezone, the e-commerce default currency | `blogname`, `timezone_string`, `woocommerce_currency` (opt-in store settings) | Short name, domains, locales and data collection settings are hashed with the shop. Webflow's API has no store address, email, weight unit or tax setting; those settings are left as they are. |
| Assets | Media library attachments | The hosted original with its alt text; display and file names, type, size and folder are meta. |
| E-commerce categories (the Categories collection's items) | `product_cat` terms | Name, slug, rich text description, image; other fields and draft or archived state are meta. `/category/<slug>` 301s to the term. |
| Products and SKUs | Simple product (one SKU, no options) or variable product with a variation per SKU | SKU ids are the variations' identities (what order items name); the options a SKU selects (`sku-values` through `sku-properties`) are its attributes. Price; a compare-at price above it makes it the regular price and the price the sale price. SKU code (optional generated `webflow-<id>`), the SKU's main image (variation image), the default SKU's main and more images (product images). Drafts are drafts, archived products private. Not shippable, digital or service products are virtual; tax category `standard-exempt` is untaxed. Tax category, product type, custom fields and each SKU's other fields are meta. `/product/<slug>` 301s to the product. |
| Inventory | Stock per variant | `finite` → managed at its quantity; `infinite` → unmanaged. |
| Buyers | WooCommerce customers (`webflow-<hash>`) | Webflow keeps no customer object for buyers, so each is gathered from their orders by email (lower-cased): the first order's name (as the first name) and addresses, dated when it was placed. |
| Orders | WooCommerce orders with Webflow's totals | Order id as the number. `pending` → pending, `unfulfilled` → processing, `fulfilled` → completed, `disputed` → on hold, `refunded` and `dispute-lost` → refunded with one refund of what was paid (Webflow refunds whole orders), dated when refunded. Items linked to their product and SKU with their row totals. Extras: shipping (less shipping discounts) as the shipping line, discounts as the discount total, tax as an order-level tax line. Customer comment, Stripe charge or PayPal capture as the transaction id, payment processor as the payment method. The tracking number and provider are a note once fulfilled. Stripe and PayPal ids, card brand and last digits, net amount and fee, custom checkout data and download files are meta. |
| Static pages | WordPress pages | Title, slug, SEO title and description, and the page's DOM content in order: text as its HTML, images with their asset URL and alt text, the text component instances override. Form fields and buttons and the Open Graph fields are meta. Drafts are drafts, archived pages private. The published path 301s to the page. A collection's template page is its items, not a page. |
| The blog collection (named by the operator) | A post category | Its collection page `/<slug>` 301s to it. |
| Blog posts | Posts in that category | Name, slug, first rich text field as the body, first image field as the featured image, created date; drafts are drafts, archived posts private; other fields (summaries, authors as references) are meta. `/<collection>/<slug>` 301s to the post. |
| Every other CMS collection | A custom post type the plugin registers (`iim_<hash of the collection>`, URL base the collection's slug) | Name, singular name; field definitions are meta. |
| CMS items | Posts of their collection's post type | As blog posts: name, slug, rich text body, image, dates, status; every other field by slug in `_mirror_fields`, references as the referenced items' identities. `/<collection>/<slug>` 301s to the item. |
| 301 redirects | Redirects the plugin serves | A target on the site resolves to wherever its object was mirrored; another target keeps its URL. |

## Planned (operator decisions)

| Webflow | What it needs |
|---|---|
| Page design (layout, styles, interactions, symbols) | The Designer's output has no API; pages keep their content. A theme port is a design job. |
| SKU subscriptions (`ec-sku-billing-method: subscription` and its plan) | Kept as meta and warned about; the selling-plans companion (`wp-woo-selling-plans`) is to hold them. |
| Discount codes | Webflow's Data API has no discounts endpoint; orders keep what was taken off. |
| Per-line discounts and taxes | Webflow reports discounts and tax only for the whole order; lines keep their row totals. |
| SKU weights and dimensions | Not in the SKU schema (an order item carries them); a SKU's custom fields are meta. |
| Downloadable files of digital products | Orders list their download files (meta); the product's files are not in the product schema. |
| Form submissions | Forms data isn't store or content history; reading it is a separate decision (FluentCRM or a forms plugin). |
| Secondary locales | One locale (the primary) per import; a translation plugin with its own add-on is the planned home. |
| Membership users and access groups | Webflow's Users API (memberships) is being retired by Webflow and isn't in this document. |
| Comments | The comments API is for the Designer's review comments, not site content. |

## Unverified against a live site

There are no live credentials: everything above is built from the published document and its examples, and no
live site was read. Unverified: that prices and amounts are in the currency's minor unit (the document's examples
show `2499` beside `$ 24.99`); that the e-commerce collections' slugs are `product`, `sku` and `category`; that a
discount extra's price is negative (it is taken as a positive amount either way); that a `dispute-lost` order's
customer was refunded what they paid; that orders are tax-exclusive; that the token endpoint is
`/oauth/access_token`; and that a site has at most a few hundred static pages (each page's DOM is read in full).
