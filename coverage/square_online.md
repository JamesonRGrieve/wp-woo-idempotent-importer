<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Square Online (Square API) → WordPress + WooCommerce + WooCommerce Square

The single source of truth for what the Square Online adapter mirrors. Square Online stores its catalog,
stock, customers and orders in the seller's Square account, so the adapter reads the Square API (version
2026-09-16, the pinned spec in `tools/SpecCache.php`). Every seller-owned entity of that API that a
Square Online store uses is listed. **Mirrored** means the importer writes it into a native WordPress /
WooCommerce home; fields with no native home are kept on the object as `_mirror_<field>` meta. **Gap**
means WordPress + WooCommerce (+ WooCommerce Square) have no schema for it, or Square doesn't expose it.
Gaps are decisions for the operator, tracked on the Deck card.

Access: a Square personal access token (or an OAuth access token the seller issued) with the read
scopes `MERCHANT_PROFILE_READ ITEMS_READ INVENTORY_READ CUSTOMERS_READ ORDERS_READ ONLINE_STORE_SITE_READ`,
sent as a bearer token with `Square-Version: 2026-09-16`. Square has webhooks, but the plugin's webhook
endpoint is Shopify's; scheduled runs keep the mirror current. Every kind is incremental: categories and
items by catalog `begin_time` (deleted objects included, so their copies are retired rather than
re-created), stock by the counts changed since the last run (their items are re-read), customers and
orders by `updated_at`. Full runs retire the copies of anything Square no longer has.

"The main location" is the seller's `main_location_id` (else its first active location): the location
whose prices, stock and presence the products mirror.

## Mirrored

| Square | WordPress / WooCommerce home | Notes |
|---|---|---|
| Seller business name and currency | `blogname`, `woocommerce_currency` (opt-in store settings) | |
| Main location email, timezone and address | `woocommerce_email_from_address`, `timezone_string`, store address (opt-in) | Address lines 2 and 3 share address 2. Square has no store-wide weight unit or tax display setting; those are left as they are. |
| Main location return policy | A page | |
| Main location phone, website, description, logo, social handles, hours, coordinates, MCC, type; every location (id, name, status); language; Square Online sites (title, domain, published) | Shop meta | No native WooCommerce field. |
| Catalog categories (every type) | `product_cat` terms | Square Online permalink as the slug, first image as the thumbnail, SEO title and description. Parent, type, top-level flag, online visibility and channels are meta. |
| Catalog items (regular, food and beverage, digital) | Products | Archived items, and items not present at the main location, are kept private; the rest publish. Description HTML as Square stores it. Square Online permalink as the slug, SEO title and description. Digital items are virtual. Square has no item creation time, so a product is dated when it is first mirrored. |
| Item variations | Simple product (one variation, no options) or variable product with global attributes and variations | A variation is identified by its Square id (stable across edits), the same id an order line names. Told apart by item option values (option display name → value), or by name ("Variation") when the item has no item options. SKU, UPC as GTIN, taxable. |
| Variation price | Regular price | The main location's price override wins. |
| Inventory (in-stock count at the main location) | Stock quantity, managed when Square tracks it | Sold out at the main location counts as zero, as Square says to treat it. Untracked variations are always in stock. |
| Item and variation images | Media library, featured image + gallery (item order); variation image | Caption as alt text. |
| Item modifier lists, tax ids, channels, label colour, abbreviation, buyer-facing name, reporting category, food and beverage details, location presence, custom attribute values; each variation's location overrides, inventory alert, sellable/stockable, vendor information | Product meta | No native WooCommerce field. |
| Old Square Online product URLs (`ecom_uri`) | 301 redirects to the product | Square marks `ecom_uri` deprecated; items without one get no redirect. |
| Customers | WooCommerce customers (`square-<customer id>`) | Name, email, phone, note, creation time; the one address as billing and shipping, with the company. Nickname, birthday, reference id, groups, segments, tax ids, email preference and creation source are meta. Passwords are never available; customers reset theirs at cutover. |
| Orders (open, completed, canceled) at every location | WooCommerce orders (HPOS-safe), Square's totals | Number shown as `#<order id>`. Open and unpaid → pending, open and paid → processing, completed → completed, canceled → cancelled, fully refunded → refunded. |
| Order line items | Line items with their own taxes | Linked to the product and variation; variation name, modifiers and note as item meta; subtotal before and total after the line's discounts. |
| Order taxes | Tax lines with their rate | Inclusive taxes set "prices include tax". |
| Order discounts (catalog or ad hoc) | Discount total; each discount as order meta | Square discounts aren't codes, so no WooCommerce coupon. |
| Shipping and delivery service charges | Shipping lines (with their taxes) | A service charge named for shipping or delivery, or for the shipment's shipping type, on an order with a shipment or delivery. |
| Other service charges, tips, cash rounding | Fees | |
| Refunds on the order (approved or pending) | WooCommerce refunds (no money moves, nothing restocked) | |
| Fulfillments (shipment, pickup, delivery, in-store) | A private order note per state reached, once each | Carrier, tracking number and URL; the shipment, pickup or delivery note is the customer note; the recipient is the order's address, email and phone. |
| Payment | Paid date, payment method title (tender types), transaction id (payment id) | |

## Gaps

| Square | Why not mirrored | What it would need |
|---|---|---|
| Gift card, appointment service, event, donation, and legacy Square Online service and membership items | No WooCommerce product equivalent (reported as unsupported) | Gift card / bookings / events / donations plugins |
| Items sold by a measurement unit, fractional stock or line quantities | WooCommerce sells and counts whole units (reported as unsupported) | A measurement-price plugin |
| Variable-priced variations | Price is set at the time of sale; WooCommerce has no price for them, so they can't be bought (a warning is logged) | — |
| Stock at other locations | WooCommerce has one stock level; the main location's is mirrored | A multi-location inventory plugin |
| Item weight and dimensions | Not exposed by the Square Catalog API | Entered in WooCommerce |
| Modifier lists (add-ons) | No WooCommerce add-on schema; kept as meta | A product add-ons plugin |
| Category hierarchy | The writer doesn't set term parents; the parent is meta | Parent support in the term writer |
| Catalog discounts, pricing rules, product sets | Automatic POS-style rules, not codes; WooCommerce coupons need a code | A dynamic-pricing plugin |
| Return and exchange orders (itemized returns) | WooCommerce keeps returns as refunds on the sale order (reported as unsupported); the sale order's own refunds are mirrored | Linking each return order to its sale order |
| Orders mixing inclusive and additive taxes; service charges apportioned into line items | WooCommerce prices either include tax or don't; apportioned charges are already inside line totals (reported as unsupported) | — |
| Draft orders | Unsubmitted carts and API drafts, not store history | — |
| Square Online site pages, blog, navigation, redirects, reviews | No public API | Manual migration |
| Loyalty, gift card balances, subscriptions, invoices, bookings, payouts, disputes | Square business programs and accounting, not catalog or order data | Dedicated WooCommerce extensions or an accounting integration |
| Square webhooks | The plugin's webhook endpoint verifies Shopify's signatures only | A Square webhook verifier |
