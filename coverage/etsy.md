<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Etsy Open API v3 → WordPress + WooCommerce + WooCommerce Square

The single source of truth for what the Etsy adapter mirrors. Every shop-owned Etsy entity the Open API
v3 (spec 3.0.0) exposes is listed. **Mirrored** means the importer writes it into a native WordPress /
WooCommerce home; fields with no native home are kept on the object as `_mirror_<field>` meta. **Gap**
means WordPress + WooCommerce (+ WooCommerce Square) have no schema for it, or Etsy doesn't expose it.
Gaps are decisions for the operator, tracked on the Deck card.

Access: an Etsy app (keystring + shared secret) and the shop owner's OAuth consent with the read-only
scopes `listings_r transactions_r shops_r email_r`. Etsy has no webhooks; scheduled runs keep the mirror
current (receipts, buyers and reviews incrementally; listings, sections and the shop in full, unchanged
objects skipped by hash). Etsy's rate limits are per app (shown in its Developer Portal) and reported on
every response: when `x-remaining-this-second` reaches the last request, the next one waits out the
second; a 429's Retry-After is honoured, and a spent daily quota (a rolling 24 hours) pauses the run until
Etsy says to retry (README, "How it stays idempotent", for pauses and the credential hold).

## Mirrored

| Etsy | WordPress / WooCommerce home | Notes |
|---|---|---|
| Shop name, currency | `blogname`, `woocommerce_currency` (opt-in store settings) | Etsy has no store email, timezone, weight unit or address; those settings are left as they are. Title, announcement, sale message, vacation message, location country and icon are meta. |
| Shop policies (welcome, payment, shipping, refunds, additional, seller info, privacy) | One page each | Privacy becomes WordPress's privacy page. |
| Structured return policies | One page each | Written out from accepts returns / exchanges and the deadline. |
| Shop sections | `product_cat` terms | Rank is meta. |
| Listings (active, inactive, sold out, draft, expired) | Products | Active and sold out publish; draft stays draft; inactive and expired are kept private. Title and description entities decoded; description paragraphs as HTML. |
| Listing inventory (products, offerings, property values) | Simple product or variable product with global attributes and variations | A variant is identified by its listing and property values (Etsy renumbers inventory product ids on every edit). Price, SKU, quantity, enabled (disabled = 0 stock). |
| Listing images | Media library, featured image + gallery (by rank) | Alt text kept. |
| Listing tags | `product_tag` terms | |
| Listing weight | Product weight in WooCommerce units | |
| Listing materials, styles, who/when made, supply, type, taxonomy id, processing days, dimensions, shipping profile id, return policy id, personalization, translations, videos, URL | Product meta | No native WooCommerce field. |
| Buyers | WooCommerce customers (`etsy-<user id>`, email) | Etsy exposes only the buyer's id and email; a receipt's name and address are the recipient's. Passwords are never available; customers reset theirs at cutover. |
| Receipts | WooCommerce orders (HPOS-safe), Etsy's totals | Status from Etsy's status and shipping; order number shown as `#<receipt id>`. |
| Receipt transactions | Order line items | Linked to the product and variation; variation choices and personalization answers as item meta. |
| Receipt shipping | One shipping line | Method from the transactions. |
| Sales tax, VAT | Order tax lines (order-level amounts) | Etsy doesn't split tax per line. |
| Discounts, gift wrap, gift message/sender | Discount total, a "Gift wrap" fee, order meta | Coupon codes aren't exposed. |
| Receipt refunds | WooCommerce refunds (no money moves, nothing restocked) | Identified by receipt and refund time. |
| Shipments (carrier, tracking) | Private order notes, once each | |
| Buyer message, seller message | Customer note, a private order note | |
| Transaction reviews | Verified product reviews | Attributed to "Etsy buyer" (Etsy shows reviewer names only on etsy.com); photo URL and language are meta. |

## Gaps

| Etsy | Why not mirrored | What it would need |
|---|---|---|
| Shipping profiles (destinations, upgrades) | Assigned per listing, not store-wide; WooCommerce zones are store-wide | Shipping classes or a table-rate plugin; the profile id is kept on each product |
| Listing variation images | Separate endpoint per listing, no variation image in the listing response | One extra request per listing; not yet read |
| Digital listing files | The API lists file names, not downloadable content | Manual upload to the downloadable product |
| Coupon codes and sales/promotions | Not exposed by Open API v3 (only receipt discount amounts) | — |
| Payments, ledger entries | Etsy Payments accounting, not order data | An accounting integration |
| Holiday preferences, production partners, readiness states | Etsy operations settings with no WooCommerce equivalent | — |
| Old Etsy URLs | Listings live on etsy.com, which the store doesn't control | — |
